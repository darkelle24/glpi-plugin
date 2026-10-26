# Déploiement Docker / TrueNAS SCALE

## Application GLPI existante : montage et copie

Ces instructions visent TrueNAS SCALE avec Apps Docker (24.10 et versions ultérieures). Ne recréez pas votre base ni vos volumes GLPI existants. Relevez le nom du conteneur GLPI et ses montages dans l'interface TrueNAS, ou avec :

```sh
docker inspect NOM_CONTENEUR_GLPI --format '{{json .Mounts}}'
docker exec NOM_CONTENEUR_GLPI php /var/www/glpi/bin/console --version
```

Les chemins ci-dessous correspondent à l'image officielle `glpi/glpi:12.0.0`. Une autre image peut placer GLPI ailleurs : adapter alors le chemin cible et les commandes. Le plugin est prévu pour GLPI 12.0.x, pas pour une ancienne image 10/11.

1. Créer ou réutiliser le dataset hôte `/mnt/Apps/GLPI/plugins`. Le parent et ses ACL doivent permettre au compte du conteneur de traverser les dossiers et lire les fichiers. Vérifier l'identité réelle avec `docker exec NOM_CONTENEUR_GLPI id` ; ne pas supposer qu'elle est toujours `33:33` et ne pas appliquer `chmod -R 777`.
2. Décompresser l'archive dans un emplacement de préparation, par exemple `/mnt/Apps/GLPI/plugin-src`. Vous obtenez `/mnt/Apps/GLPI/plugin-src/mailsignaturecleaner/setup.php`.
3. Installer les fichiers avec le script fourni :

```sh
PLUGINS_DIR=/mnt/Apps/GLPI/plugins sh /mnt/Apps/GLPI/plugin-src/mailsignaturecleaner/deploy/install.sh
```

Le script copie uniquement `mailsignaturecleaner`. Il ne clone rien, ne supprime rien récursivement et ne modifie pas les autres plugins. Il prépare un nouveau dossier puis bascule celui-ci à la place de l'ancien. L'ancienne version est déplacée par défaut dans `/mnt/Apps/GLPI/plugin-backups/mailsignaturecleaner/<date>-<pid>` ; `BACKUP_DIR` permet de changer cet emplacement, obligatoirement hors du répertoire des plugins. En cas d'échec avant bascule, un dossier `.msc-stage-*` peut rester pour diagnostic.

4. Ajouter le montage au **service GLPI existant**, en conservant tous ses volumes, variables, ports et liens à la base :

```yaml
services:
  glpi:
    volumes:
      - type: bind
        source: /mnt/Apps/GLPI/plugins
        target: /var/www/glpi/plugins
        read_only: true
        bind:
          create_host_path: false
```

Le montage du dossier complet permet au remplacement de `mailsignaturecleaner` par le script d'être visible dans le conteneur. Le montage en lecture seule convient à ce plugin : sa configuration est en base et son journal dans les fichiers GLPI. Si vos autres plugins ont besoin d'écrire dans leur dossier, conservez la politique adaptée à votre installation. Un montage existant sur `/var/www/glpi/plugins` doit être réutilisé plutôt que doublé. Le code ne doit pas aussi exister dans `marketplace/mailsignaturecleaner` : GLPI pourrait charger deux copies.

Avec Docker Compose en ligne de commande, le fichier `compose.override.yaml` fourni utilise `GLPI_PLUGINS_HOST` de `.env.example`. Placez-le avec votre Compose existant et fusionnez :

```sh
docker compose -f compose.yaml -f compose.override.yaml config
docker compose -f compose.yaml -f compose.override.yaml up -d glpi
```

Dans l'éditeur YAML de TrueNAS, intégrer le montage directement au YAML de l'application. Ne pas compter sur un `.env` placé à côté de l'archive : remplacer le chemin par une valeur absolue dans l'éditeur, ou utiliser le mécanisme de variables offert par votre version. Si l'application vient du catalogue, ajouter un stockage hôte via ses paramètres si disponible ; sinon la conversion vers une Custom App doit préserver ses volumes existants et sa base. Le plugin ne nécessite pas de reconstruire l'image.

5. Redémarrer le conteneur GLPI pour renouveler OPcache, puis vérifier :

```sh
docker exec NOM_CONTENEUR_GLPI php -l /var/www/glpi/plugins/mailsignaturecleaner/setup.php
docker exec NOM_CONTENEUR_GLPI php /var/www/glpi/bin/console plugin:list
```

Installer et activer depuis **Configuration → Plugins**, puis suivre le démarrage en simulation du README. Alternative en console, une fois les fichiers en place :

```sh
docker exec NOM_CONTENEUR_GLPI php /var/www/glpi/bin/console plugin:install mailsignaturecleaner
docker exec NOM_CONTENEUR_GLPI php /var/www/glpi/bin/console plugin:activate mailsignaturecleaner
```

Si votre politique demande une identité d'installation, ajouter `--username NOM_ADMIN` à `plugin:install` (nom d'un utilisateur GLPI autorisé). Vérifier l'aide de votre version avec `plugin:install --help`.

## Préparation depuis votre dépôt Git

Mettre le dossier livré `mailsignaturecleaner` dans votre dépôt, par exemple à sa racine. Cloner le dépôt dans un dossier de préparation **hors du dataset des plugins**, choisir un tag ou un commit contrôlé, puis lancer :

```sh
git clone URL_DE_VOTRE_DEPOT /mnt/Apps/GLPI/plugin-src/repo
git -C /mnt/Apps/GLPI/plugin-src/repo checkout VOTRE_TAG_OU_COMMIT
PLUGINS_DIR=/mnt/Apps/GLPI/plugins sh /mnt/Apps/GLPI/plugin-src/repo/mailsignaturecleaner/deploy/install.sh
```

Si le plugin est directement à la racine du dépôt, utiliser `repo/deploy/install.sh`. Ne pas lancer `git pull` automatiquement à chaque démarrage de GLPI : préparer et tester une version avant remplacement. Les identifiants Git éventuels restent dans le mécanisme d'authentification du dépôt ; aucun secret n'est requis par ce plugin.

## Collecteur et tâches automatiques

Configurer votre collecteur IMAP dans GLPI comme d'habitude. Le plugin ne reçoit pas les identifiants IMAP et n'ouvre aucune connexion mail. Mettre l'action automatique `mailgate` en mode CLI avec une fréquence appropriée. L'image officielle dispose d'un ordonnanceur si `GLPI_CRONTAB_ENABLED=1`. Sur une installation avec plusieurs conteneurs web, n'exécuter cet ordonnanceur que sur le worker prévu pour éviter les collectes simultanées. Vérifier la dernière exécution de `mailgate` et le journal GLPI après le test.

## Laboratoire indépendant

`compose.lab.yaml` lance GLPI 12.0.0 avec une base séparée et un accès sur le port hôte 8088. Il est réservé à une installation neuve pour recette ; le changer de nom ne constitue pas une migration de votre instance existante.

Pour l'utiliser avec Compose, définir dans un fichier `.env` du répertoire de lancement :

```dotenv
GLPI_PLUGINS_HOST=/mnt/Apps/GLPI/plugins-lab
GLPI_DB_PASSWORD=REMPLACER_PAR_UN_MOT_DE_PASSE_LONG_UNIQUE
```

Créer `plugins-lab`, y installer le plugin avec `PLUGINS_DIR`, puis :

```sh
docker compose -f compose.lab.yaml config
docker compose -f compose.lab.yaml up -d
```

Avec TrueNAS : **Apps → Discover Apps → Install via YAML**, copier le YAML et remplacer les deux variables `${…}` par vos valeurs. Le nom de projet de l'application est géré par TrueNAS. Ne pas rendre cette instance de recette accessible publiquement avec les comptes initiaux ; terminer la configuration habituelle de GLPI avant utilisation.

## Mise à jour et retour arrière

Avant mise à jour, sauvegarder la base et les données GLPI selon votre procédure habituelle, et relever les règles. Suspendre la collecte / désactiver le plugin pendant le remplacement du code. Relancer `install.sh` avec les nouveaux fichiers : il conserve l'ancienne version. Redémarrer GLPI, contrôler `plugin:list`, réactiver et refaire un test avant/après puis un cycle de recette.

Pour revenir en arrière, désactiver le plugin et suspendre la collecte. Renommer le dossier courant dans un emplacement d'archive hors du dossier `plugins`, puis recopier la sauvegarde voulue sous `plugins/mailsignaturecleaner`, avec droits de lecture corrects. Redémarrer GLPI et réactiver la version restaurée. Le remplacement du code ne supprime pas les règles en base ; une désinstallation depuis GLPI les efface. Une restauration du code ne restaure pas le texte des suivis déjà nettoyés.

## Dépannage

- Plugin absent : vérifier `plugins/mailsignaturecleaner/setup.php`, l'orthographe, le montage et les droits de lecture dans le conteneur.
- Refus de compatibilité : vérifier GLPI 12.0.x, PHP ≥ 8.3, DOM et le moteur CSS Symfony fourni par GLPI.
- Page 404 : accéder par l'URL GLPI normale avec son éventuel préfixe ; le serveur doit pointer sur `public/`, comme requis par GLPI 12.
- Configuration inaccessible : plugin activé et droit `config/UPDATE` requis ; l'API des anciens jetons CSRF GLPI n'est pas utilisée.
- Signature présente : vérifier que le nettoyage est activé, simulation décochée, puis consulter la page de test et les statuts du journal. Les protections de citations/images ou la limite de suppression peuvent conserver le message.
- Règle qui ne correspond pas : le collecteur peut avoir modifié le HTML ; tester la représentation effectivement transmise au suivi. Le plugin n'agit ni sur le HTML SMTP original déjà transformé ni sur les suivis historiques.
