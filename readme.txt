=== BK WEB Backup ===
Contributors: bkcomandmark
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later

Sauvegardes automatiques (fichiers + base de données) vers OVHcloud Object Storage (S3),
copie locale hors du site, alertes e-mail, restauration depuis l'admin et script de secours.

== Fonctionnement ==

* Archive unique .tar.gz : database.sql + dossier files/ + bkwb-manifest.json.
  Elle s'ouvre avec n'importe quel outil (tar -xzf, 7-Zip, Archives macOS).
* Le travail avance par tranches de quelques secondes (limites d'exécution des hébergements
  mutualisés). Une tranche coupée par le serveur est reprise proprement.
* Envoi vers S3 en parties de 16 Mo, avec contrôle d'intégrité (Content-MD5).
* Copie locale dans /home/IDENTIFIANT/bk-web-backup/SITE (hors du dossier public) si possible.
* Restauration : extraction dans un dossier temporaire, import de la base dans des tables
  temporaires, puis bascule en une seule opération. wp-config.php et le plugin ne sont jamais
  remplacés.
* Pour Maintenance Pilote : bkwb_get_status() (état des sauvegardes) et
  bkwb_start_backup( 'full' | 'db' ) (lance une sauvegarde en arrière-plan).
* Mises à jour automatiques depuis les releases GitHub de bkcomandmark/bk-web-backup
  (fichier bk-web-backup.zip joint à chaque release, tag vX.Y.Z), repoussées pendant une
  sauvegarde ou une restauration. BKWB_DISABLE_AUTO_UPDATE les désactive.

== Installation sur un site (o2switch) ==

1. Installer et activer le plugin.
2. Créer une clé S3 propre au site dans OVHcloud (Public Cloud → Object Storage →
   Utilisateurs S3), puis lui appliquer la politique ci-dessous (remplacer SITE par le dossier
   du site, par ex. unat-bfc.fr).
3. BK WEB Backup → Réglages : renseigner le bucket, la clé d'accès et la clé secrète
   (ou les constantes BKWB_S3_* dans wp-config.php), enregistrer, puis « Tester la connexion ».
4. cPanel → Tâches Cron, toutes les 15 minutes :
     php /home/IDENTIFIANT/DOSSIER-DU-SITE/wp-content/plugins/bk-web-backup/bin/cron.php >/dev/null 2>&1
5. Lancer une première sauvegarde depuis le tableau de bord.
6. Télécharger le script de secours et le ranger hors du serveur.

== Politique S3 d'une clé de site ==

Lecture et écriture dans le dossier du site uniquement, aucune suppression :

{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ListeDuDossierDuSite",
      "Effect": "Allow",
      "Action": [ "s3:ListBucket" ],
      "Resource": [ "arn:aws:s3:::bk-sauvegardes-wordpress" ],
      "Condition": { "StringLike": { "s3:prefix": [ "SITE/*" ] } }
    },
    {
      "Sid": "ObjetsDuDossierDuSite",
      "Effect": "Allow",
      "Action": [ "s3:GetObject", "s3:PutObject", "s3:AbortMultipartUpload", "s3:ListMultipartUploadParts" ],
      "Resource": [ "arn:aws:s3:::bk-sauvegardes-wordpress/SITE/*" ]
    }
  ]
}

== Conservation sur OVH ==

Le plugin ne supprime jamais rien sur le stockage. La durée de conservation se règle sur le
bucket : verrouillage Object Lock (30 jours, mode Gouvernance) + règle de cycle de vie qui
expire les sauvegardes après 31 jours et efface les anciennes versions un jour plus tard.

== Constantes facultatives (wp-config.php) ==

BKWB_S3_ENDPOINT, BKWB_S3_REGION, BKWB_S3_BUCKET, BKWB_S3_PREFIX, BKWB_S3_ACCESS_KEY, BKWB_S3_SECRET_KEY
BKWB_LOCAL_DIR    Dossier local des sauvegardes (par défaut : à côté du dossier du site).
BKWB_TICK_BUDGET  Durée d'une tranche de travail sur le web, en secondes (si l'hébergeur coupe
                  les requêtes plus tôt que sa limite affichée).

== WP-CLI ==

wp bkwb backup [--db-only]
wp bkwb list
wp bkwb restore NOM [--remote] [--what=full|db|files] [--yes]
wp bkwb status
wp bkwb test-s3
wp bkwb cancel
