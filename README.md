# BK WEB Backup

Plugin WordPress de sauvegarde automatique (fichiers + base de données) vers OVHcloud Object Storage (S3), développé pour les sites de BK COM AND MARK hébergés chez o2switch.

- Archive `.tar.gz` standard : `database.sql`, dossier `files/`, `bkwb-manifest.json`.
- Travail découpé en tranches de quelques secondes, avec reprise après une coupure du serveur.
- Envoi S3 en parties de 16 Mo avec contrôle d'intégrité (compatible Object Lock).
- Copie locale hors du dossier public du site, alertes par e-mail, restauration depuis l'admin et script de secours autonome.
- État des sauvegardes pour Maintenance Pilote : `bkwb_get_status()`, `bkwb_start_backup()`.

## Installation

Télécharger `bk-web-backup.zip` dans la [dernière release](https://github.com/bkcomandmark/bk-web-backup/releases/latest), puis Extensions → Ajouter → Téléverser. Les mises à jour suivantes arrivent automatiquement depuis les releases de ce dépôt.

La configuration (clé S3 du site, tâche cron o2switch, politique S3) est décrite dans [readme.txt](readme.txt).

## Publier une nouvelle version

1. Mettre à jour `Version:` et `BKWB_VERSION` dans `bk-web-backup.php`.
2. Créer le zip avec le dossier `bk-web-backup/` à la racine.
3. Publier une release taguée `vX.Y.Z` avec ce zip en pièce jointe (`bk-web-backup.zip`).
