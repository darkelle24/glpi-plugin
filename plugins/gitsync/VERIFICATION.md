# Vérifications — Git Sync 2.0.0

Date : 28 octobre 2026.

## Résultat

- Python : **36 tests découverts, 35 réussis et 1 ignoré** (création réelle de lien symbolique, droits Windows insuffisants).
- PHP/Symfony/Twig : **64 assertions réussies**.
- Navigateur : suivi de statut, échappement des textes, activation des boutons après fin, conservation du formulaire en cours et soumission de la case automatique : **OK**.
- Rendu inspecté à 1500 pixels et à 390 pixels de large ; aucun débordement horizontal.
- Syntaxe PHP, JavaScript et shell : **OK**.
- Fragment Compose fusionné à une pile de test GLPI/DB : **validation OK**.

Le paquet n'a pas été installé sur votre TrueNAS ni dans une instance GLPI complète. Le worker Linux n'a pas été construit ou lancé ici : aucun Docker Engine n'est disponible dans cet environnement Windows.

## Couverture

| Domaine | Vérifications effectuées |
|---|---|
| Plusieurs dépôts | Création, modification, persistance SQLite, pagination, 29 entrées sans plafond fixe |
| Validation | Types, noms de branches, URL HTTPS sans identifiant, chemins et scripts relatifs, refus de traversée et de chevauchement |
| Permissions GLPI | Interface centrale, plugin actif, config UPDATE, liste de profils et appel à la réauthentification native |
| CSRF | Jeton de session, expiration, valeur erronée, consommation et refus du rejeu |
| Planificateur | Passage automatique sans requête navigateur, délai, désactivation, sérialisation, reprise après interruption |
| États | En attente/en cours/succès/erreur, dates, messages, code d'échec et prochaine tentative |
| Git | Premier clone, pull sans changement, changement de commit, branche/dépôt attendu, refus des modifications locales et dossiers non Git |
| Scripts | Facultatif, exécution manuelle même sans changement, exécution automatique après changement, nouvelle tentative après déploiement échoué de la même révision |
| Processus | Processus réels pour capture, code non nul et délai d'attente ; sortie de journal bornée |
| API HTTP réelle en boucle locale | Authentification avant mutation, routes/méthodes, origine navigateur refusée, configuration et lancement |
| Client PHP | URL du service fixe, JSON, pas de redirection/proxy, secret, erreurs HTTP et réponse trop grande, pas de retry d'une mutation |
| Contrôleurs | Attributs de routes Symfony, sous-chemin /glpi, POST seulement pour les mutations, confirmations de retrait |
| Rendu réel Twig | Toutes les cartes, mode vide, édition, erreur service et échappement des noms/logs |
| Navigateur réel | Actualisation dynamique, texte hostile affiché sans HTML, saisie conservée, case automatique envoyée |
| Compose | Aucun port publié, aucun socket Docker, utilisateur non-root, réseau privé, réseau sortant, volumes et secret |

Les commandes Git et le script du dépôt sont simulés dans les tests de synchronisation : aucun clonage de votre dépôt ni déploiement GLPI n'a été exécuté. Les tests de processus utilisent des programmes Python locaux, pas votre deploy.sh.

Les tests PHP simulent la session GLPI, la réauthentification et le transport cURL. Ils utilisent réellement Symfony 7.4.21 et Twig 3.30.0. Le parent de mise en page GLPI est remplacé par une coquille de test pour le rendu ; le thème complet et la base GLPI ne sont pas démarrés.

Le contrôle supplémentaire de liens symboliques est présent côté serveur. Le test qui nécessite un vrai lien symbolique est ignoré sous Windows ; le vérifier sur le NAS avant d'exposer le service à des administrateurs.

## Référence GLPI

Les contrôleurs, préfixes de routes et contrôles de sécurité suivent les interfaces étudiées du tag GLPI **12.0.0** :

- PluginRoutesLoader : découverte automatique dans src/Controller et noms de routes @pluginsync:...
- Config::checkGlobal(UPDATE) : permission et réauthentification.
- CheckCsrfListener : contrôle natif Fetch Metadata / Origin, conservé par le plugin.
- Layout page_without_tabs : structure de la page.
- PHP >=8.3, Symfony ^7.4 et Twig ^3.27, conformes au composer.json de GLPI 12.

La version vise GLPI 12.x ; les interfaces des futures versions n'ont pas été exécutées ni certifiées.

## Rejouer les tests

Depuis la racine du paquet, sur un poste de test :

    python -m unittest discover -s tests -v
    composer install --working-dir=tests --no-interaction
    php tests/test_plugin.php

Python >=3.12, PHP >=8.3 avec cURL et mbstring. Les bibliothèques PHP de test sont verrouillées dans tests/composer.lock ; elles ne sont pas nécessaires au plugin installé dans GLPI.

Pour générer l'aperçu depuis le vrai template :

    mkdir outputs
    php tests/test_plugin.php tests/vendor/autoload.php outputs/APERCU-GIT-SYNC.html

Le test navigateur tests/test_ui.cjs utilise Playwright installé dans l'environnement de test et un navigateur Chromium disponible :

    node tests/test_ui.cjs outputs/APERCU-GIT-SYNC.html

Par défaut, Playwright utilise son Chromium. La variable GIT_SYNC_BROWSER permet de sélectionner un canal installé, par exemple msedge. Ce test ouvre uniquement un aperçu local et des réponses réseau simulées.

Ne pas installer ces outils de test dans l'OS de base TrueNAS. Docker Compose a été validé localement avec la version 5.6.1 ; contrôler aussi la fusion dans votre gestionnaire TrueNAS.

## À valider sur votre installation

1. Accès aux volumes et au secret par l'UID du worker et le groupe PHP.
2. Démarrage de l'image Linux, connectivité Git HTTPS et montage réel des chemins.
3. Installation du plugin dans GLPI, session, réauthentification et thème.
4. Fonctionnement de votre deploy.sh, permissions des plugins/marketplace et codes d'erreur.
5. Premier essai manuel, résultat réel dans GLPI, puis passage automatique.
6. Refus d'accès pour un profil non autorisé et absence de socket Docker/port public.

L'aperçu HTML et sa capture sont une démonstration visuelle avec données fictives et actions désactivées. Ils ne prétendent pas représenter une installation effective sur votre NAS.
