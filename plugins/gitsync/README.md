# Git Sync 2.0.0 — GLPI 12 et TrueNAS SCALE

Git Sync gère plusieurs dépôts depuis une page GLPI : un bouton par dépôt, un statut **En cours / Erreur / Succès**, la date du dernier essai et une case **Pull automatique**. Chaque dépôt possède son adresse, sa branche, son chemin de clonage et son script shell facultatif.

Pas de limite fixe au nombre de dépôts ; la liste est paginée par 12. La configuration et les résultats persistent dans la base SQLite du service, hors du dossier des plugins.

## Ce qui change depuis le premier paquet

Cette version **remplace le déclenchement fixe de glpi-plugin-sync par un service Git dédié**. Le nom affiché est **Git Sync** ; le nom technique du plugin reste pluginsync pour la continuité dans GLPI.

Le nouveau service clone et met à jour les dépôts lui-même. Il ne monte aucun socket Docker, n'appelle pas Docker, fonctionne sans privilèges Linux et sous l'utilisateur 568 par défaut. GLPI ne monte pas non plus de socket Docker.

Le service reste actif en arrière-plan : les passages automatiques fonctionnent même quand le navigateur est fermé. Désactiver le plugin GLPI ne désactive pas le planificateur ; pour l'arrêter, décocher les cases ou arrêter le service worker.

## Boutons et automatisation

| Situation | Comportement |
|---|---|
| Premier passage | Clone la branche choisie, puis exécute le script facultatif |
| Bouton Synchroniser | Met à jour le clone et lance le script, même sans nouveau commit |
| Pull automatique | Met à jour le clone ; lance le script après une nouvelle révision ou si cette révision n'a pas encore été déployée avec succès |
| Aucun script | Clone ou met à jour les fichiers uniquement |
| Déjà à jour en automatique | Succès ; ne relance pas un script déjà réussi pour cette révision |
| Modifications locales, mauvaise branche, historique divergent | Erreur ; aucun écrasement forcé |
| Plusieurs dépôts demandés | Les traite l'un après l'autre pour protéger les volumes GLPI partagés |
| Retirer une entrée | Retire la configuration et sa planification ; conserve les fichiers |
| Redémarrage pendant un essai | Marque l'essai incertain en Erreur ; pas de rejeu immédiat |

Intervalle réglable par dépôt : de 5 minutes à 7 jours, **15 minutes par défaut**. Le premier passage automatique intervient après cet intervalle ; utiliser le bouton pour lancer immédiatement.

Les intervalles sont calculés après la fin d'une tentative et peuvent être retardés par les autres dépôts en attente. En cas d'erreur, le prochain passage retente si la case reste cochée.

## Prérequis et contenu

- TrueNAS SCALE avec le backend Docker/Compose natif, introduit en 24.10 ; utiliser une version encore maintenue.
- GLPI 12.x, PHP >=8.3, extensions GLPI habituelles dont cURL et mbstring. Interfaces étudiées sur le code officiel du tag 12.0.0.
- GLPI en HTTPS via son point d'entrée public/, en conservant les en-têtes CSRF Fetch Metadata / Origin.
- Dépôts HTTPS sans identifiant dans l'URL, port standard 443. Cette version ne gère pas SSH, les dépôts privés nécessitant un jeton, les sous-modules ou Git LFS.
- Un seul worker, base SQLite sur un dataset local, pas sur un partage SMB/NFS.

Le paquet contient : pluginsync/ (plugin), worker/ (service et Dockerfile), compose.additions.yaml (fragment à fusionner), tests/, VERIFICATION.md et LICENSE (GPL-3.0-or-later).

## 1. Copier les fichiers

Extraire puis transférer :

    worker/      → /mnt/Apps/GLPI/git-sync/worker/
    pluginsync/  → /mnt/Apps/GLPI/plugins/pluginsync/

Copier seulement pluginsync/ dans les plugins de GLPI. Garder le worker, le secret, la base et les tests hors du dossier web. Conserver la cible actuelle du montage de vos plugins dans GLPI.

## 2. Construire l'image et préparer les dossiers

Depuis le Shell TrueNAS avec les droits Docker :

    mkdir -p /mnt/Apps/GLPI/git-sync/data /mnt/Apps/GLPI/repos
    cd /mnt/Apps/GLPI/git-sync
    docker build --pull -t local/git-sync:2.0.0 ./worker
    sh ./worker/generate-token.sh

La construction nécessite Internet. Elle ne modifie pas les paquets de l'OS TrueNAS. Le générateur crée un secret aléatoire de 256 bits, sans l'afficher, et refuse de remplacer un secret existant.

## 3. Donner les permissions

Le worker utilise **UID 568 / GID 568**. Pour de nouveaux dossiers dédiés :

    chown 568:568 /mnt/Apps/GLPI/git-sync/data /mnt/Apps/GLPI/repos
    chmod 700 /mnt/Apps/GLPI/git-sync/data
    chmod 750 /mnt/Apps/GLPI/repos

Le secret doit être lisible par le worker et PHP. Exemple **uniquement si PHP utilise le GID 33** :

    chown 568:33 /mnt/Apps/GLPI/git-sync/secrets/token
    chmod 640 /mnt/Apps/GLPI/git-sync/secrets/token

Adapter 33 au vrai groupe PHP et 568 si vous changez l'utilisateur du worker. Les deux conteneurs montent ce fichier en lecture seule.

Si le script déploie dans plugins/ ou marketplace/, donner au worker les droits nécessaires sur les deux volumes via les ACL ou un groupe partagé. **Ne pas changer récursivement le propriétaire de vos plugins existants sans vérifier les besoins de GLPI.** Pas de chmod 777. Le code du plugin doit rester lisible par PHP, généralement fichiers 644 et dossiers 755.

## 4. Fusionner le Compose

Dans l'application TrueNAS existante, fusionner compose.additions.yaml :

1. Ajouter à glpi le secret, la variable facultative de profils et le réseau privé.
2. **Conserver tous ses réseaux actuels**, dont celui de glpi-db et du proxy. Le réseau default du fragment est un exemple à adapter.
3. Ajouter pluginsync-worker et les deux réseaux définis en bas du fichier.
4. Garder pull_policy: never : l'image vient d'être construite sur ce NAS.

Le fragment seul n'est pas une pile complète ; il ne contient ni votre image GLPI ni votre base. Ne pas modifier glpi-db. Aucun port du worker ne doit être publié. Le réseau sortant du worker est nécessaire à Git HTTPS ; son API communique avec GLPI par le réseau privé et le secret.

Conserver l'alias pluginsync-worker et la cible /repos du montage. Déployer avec votre gestionnaire habituel. Si vous utilisez des fichiers Compose, valider la fusion :

    docker compose -f compose.yaml -f compose.additions.yaml config -q

## 5. Activer dans GLPI

Dans **Configuration → Plugins**, installer/mettre à jour puis activer **Git Sync**, et ouvrir son lien de configuration.

Lien direct si GLPI est à la racine :

    https://VOTRE-GLPI/plugins/pluginsync/Admin

Préfixer par /glpi si nécessaire. Les liens internes suivent automatiquement le préfixe de GLPI.

La page exige le droit config / UPDATE et la réauthentification native GLPI 12. PLUGINSYNC_ALLOWED_PROFILES peut restreindre davantage les IDs de profils, par exemple 4,7 sans espaces ; cela ne donne aucun droit supplémentaire.

## Exemple pour votre dépôt

| Champ | Valeur |
|---|---|
| Nom | Plugins GLPI |
| Adresse | https://github.com/darkelle24/glpi-plugin.git |
| Branche | main |
| Chemin de clonage | /mnt/Apps/GLPI/repos/glpi-plugin |
| Script facultatif | deploy.sh |
| Pull automatique | À cocher pour un passage régulier |
| Fréquence | 15 minutes, ou votre choix |

Le script est un fichier shell du dépôt, par exemple scripts/deploy.sh, exécuté par /bin/sh depuis la racine du clone. Le champ n'accepte pas de commande libre ni d'arguments arbitraires.

Variables transmises :

    REPO_DIR=/repos/glpi-plugin
    REPO_URL=https://github.com/darkelle24/glpi-plugin.git
    REPO_BRANCH=main
    PLUGINS_PATH=/glpi/plugins
    MARKETPLACE_PATH=/glpi/marketplace

Votre deploy.sh n'a pas été consulté ni exécuté pendant la préparation. Vérifier qu'il utilise ces chemins, préserve pluginsync/, renvoie un code non nul en cas d'erreur et peut être rejoué sans dommage. Ne pas laisser de processus en arrière-plan.

## Migration depuis Plugin Sync 1.0

1. Sauvegarder le plugin et les volumes GLPI.
2. Laisser finir l'ancien glpi-plugin-sync, puis arrêter/retirer l'ancien bridge et son montage du socket Docker.
3. Retirer l'ancien service glpi-plugin-sync de cette pile ou empêcher son lancement concurrent. Un redéploiement Compose peut lancer un job même avec restart: no.
4. **Remplacer entièrement** plugins/pluginsync par le nouveau dossier ; ne pas superposer V2 et V1. Garder la sauvegarde hors des plugins.
5. Déployer le worker et le nouveau secret ; mettre à jour/réactiver Git Sync dans GLPI. Vider le cache GLPI si les routes restent anciennes.
6. Ajouter votre dépôt, tester manuellement puis activer l'automatisation.

L'image alpine/git:v2.45.2 de l'ancien job n'est plus nécessaire pour Git Sync V2.

## Paramètres à adapter

| Élément | Réglage |
|---|---|
| Fichiers de service | /mnt/Apps/GLPI/git-sync |
| Racine NAS des clones | /mnt/Apps/GLPI/repos : adapter le montage ET PLUGINSYNC_HOST_REPO_ROOT |
| Racine dans le worker | /repos, fixe |
| UID/GID worker | 568:568 dans le Compose et les permissions |
| Groupe PHP | Propriétaire/groupe/ACL du secret selon votre image |
| Réseaux GLPI | Conserver les réseaux actuels et ajouter le réseau privé |
| Déploiement | Sources des montages /glpi/plugins et /glpi/marketplace |
| Profils | PLUGINSYNC_ALLOWED_PROFILES, facultatif |
| Dépôts/scripts/intervalles | Dans l'interface, aucun YAML à modifier par dépôt |

Le chemin du formulaire peut être relatif (glpi-plugin) ou le chemin NAS complet sous la racine autorisée. Les chemins ailleurs sur le NAS, liens symboliques dans le chemin et dossiers qui se chevauchent entre dépôts sont refusés.

## Protections et limites

- Permissions vérifiées côté serveur pour chaque action, réauthentification GLPI 12, CSRF natif et jeton supplémentaire à usage unique valable 30 minutes.
- API privée avec secret, vérification en temps constant, pas de CORS ni d'accès navigateur direct.
- HTTPS uniquement, pas de commande libre, hooks Git et redirections HTTP désactivés.
- Mise à jour fetch puis merge --ff-only : ni reset forcé ni suppression automatique des clones.
- Un traitement à la fois ; délai maximal de 5 minutes par commande Git et 15 minutes pour le script.
- Journal borné à 256 Kio par dernière tentative ; affichage des 64 derniers Kio échappés en HTML. Pas d'historique illimité.
- Worker non-root, capacités retirées, volumes dédiés, aucun socket Docker.

**Les dépôts et scripts doivent être de confiance.** Un script est du code exécutable. Il possède les accès du worker aux volumes montés et peut lire les fichiers accessibles à cet utilisateur, dont le secret et la base du service. Cette version n'est pas une sandbox par dépôt. Réserver Git Sync à des administrateurs de confiance.

Une branche est mutable, sans vérification de signature de commit dans ce paquet. « Succès » signifie que Git et le script ont terminé avec code 0 ; cela ne prouve pas que GLPI fonctionne après le déploiement.

Pas de transaction, migration SQL, activation de plugin, purge OPcache ou restauration automatique. Les scripts doivent être idempotents, car une interruption peut laisser un déploiement partiel. Les commandes manuelles et outils externes restent hors du verrou Git Sync.

Le Dockerfile suit les correctifs disponibles au moment de la construction ; après validation, figer le digest Python et la version Git si vous avez besoin d'une construction reproductible.

## Sauvegarde et retrait

Sauvegarder git-sync/data avec le worker arrêté pour une copie SQLite cohérente, ainsi que les clones et volumes GLPI selon votre procédure. Garder le secret dans une sauvegarde privée.

Décocher l'automatisation n'interrompt pas un essai déjà lancé ; les modifications sont refusées pendant celui-ci. Arrêter le worker pour suspendre tous les traitements. Désinstaller seulement le plugin GLPI ne supprime pas les planifications du worker.

Pour retirer entièrement Git Sync : arrêter/retirer le worker, désactiver/désinstaller le plugin, retirer les montages/réseaux ajoutés puis archiver les données. Les déploiements effectués ne sont pas annulés.

## Dépannage et validation

- Service inaccessible : image locale construite, worker démarré, réseau privé commun, alias exact et secret identique.
- Secret illisible : vérifier UID worker, groupe PHP, ACL et montages.
- Erreur Git : vérifier URL publique HTTPS, branche, accès Internet, permissions et absence de modifications locales.
- Erreur du script : ouvrir Journal, vérifier /bin/sh, chemins, code de sortie et droits des volumes.
- Suivi de page interrompu : recharger pour réauthentifier la session. Le planificateur continue indépendamment.
- Rotation du secret : recréer GLPI et le worker pour remonter le nouveau fichier.

Après installation : tester manuellement un dépôt, vérifier les fichiers et GLPI, tester le refus avec un profil sans droits, puis valider un passage automatique. Garder l'automatisation décochée avant validation du script.

Le paquet n'a pas été installé sur votre NAS ni dans une instance GLPI complète. Détails et tests dans VERIFICATION.md.

Références : [contrôleurs GLPI](https://glpi-developer-documentation.readthedocs.io/en/master/plugins/controllers.html), [CSRF GLPI 12.0.0](https://github.com/glpi-project/glpi/blob/12.0.0/src/Glpi/Kernel/Listener/ControllerListener/CheckCsrfListener.php), [réauthentification](https://glpi-developer-documentation.readthedocs.io/en/master/devapi/reauthentication.html), [backend Docker TrueNAS](https://www.truenas.com/docs/scale/24.10/).
