# Validation et recette

## Vérifié localement

Version **1.2.0**, 28 octobre 2026 : **93 assertions** sous PHP 8.3.36 et PHP 8.5.12. Les 71 contrôles précédents sont complétés par 22 vérifications : retrait des couleurs/fonds, héritage sans couleur fixe, option désactivée par défaut, idempotence, couleurs historiques, stylesheets, citations, images et URL intactes, `!important`, WebKit, styles avec chaînes contenant un point-virgule, commentaires/noms CSS échappés, variables et resets, texte brut et code échappé, marqueurs GLPI, indépendance des signatures, tickets initiaux mail, exclusion des saisies manuelles et simulation.

Le thème est hérité par retrait des couleurs stockées, conformément au conteneur rich text du code GLPI audité. Le rendu sur l'instance GLPI réelle n'est pas testé de bout en bout ici. La recette doit comparer le même nouveau mail avec un compte en thème clair et un compte en thème sombre. Les anciens contenus ne sont pas repris automatiquement.

- Audit des sources GLPI 12.0.0, sans modification du cœur.
- Syntaxe de tous les fichiers PHP.
- 71 assertions du lanceur `tests/run.php` : sélecteurs CSS, attributs et pseudo-classes ; UTF-8 ; absence de correspondance ; idempotence ; HTML échappé ; document complet et HTML incomplet ; suppression imbriquée ; citations ; marqueurs GLPI/personnalisés/commentaires ; pièces jointes et placeholders d'images du collecteur ; résultat vide ; limite proportionnelle ; limites de taille/nombre ; déclaration d'entité refusée ; configuration invalide ; contrat du hook avec doubles ; exclusion des saisies manuelles ; simulation ; configuration initiale et désinstallation ; coupure après un repère entre plusieurs parents, textes suivants, conservation du repère, ambiguïté, repère absent/invalide et protections ; reproduction d'un en-tête Outlook conservé, suppression explicite de l'historique et maintien des autres protections ; captures JSON, absence de capture par défaut, séparation entrée/proposition/sortie, règles et compteurs, exclusion des champs arbitraires et MIME, troncature, quota et capture sans application du nettoyage ; seuil 100 % avec maintien du refus de message vide et variante de style Outlook réellement reçue.
- Moteur CssSelector exact du verrou GLPI 12.0.0 : Symfony 7.4.18, commit `fecf40067fc8d8880ea87b8ac227600b9aadd1d0`.
- Lanceur exécuté avec succès sur PHP **8.3.36** et **8.5.12** (Windows x64) ; contrôle de syntaxe sur PHP 8.3.36. Aucun runtime ou dépendance de test n'est embarqué dans le plugin.
- Script de déploiement : syntaxe POSIX vérifiée. La copie et la sauvegarde ont été exercées dans un dossier temporaire avec le shell Git Windows ; cette distribution ne fournit pas `chmod`, donc la vérification complète des permissions Linux reste à effectuer sur l'hôte cible. Le script final vérifie la présence des utilitaires avant de copier.

Les doubles `Config`, `ITILFollowup` et `Toolbox` ne démarrent pas GLPI et ne remplacent pas un test d'intégration. Aucune instance GLPI/MySQL/IMAP ni hôte TrueNAS n'était connecté à cet environnement. L'installation en base, le rendu réel de l'administration et un cycle IMAP complet restent à valider sur l'instance de recette. Aucun résultat de déploiement sur votre serveur n'est revendiqué.

Version 1.1.0 : test supplémentaire sur le HTML Outlook fourni par l'utilisateur. Avec le sélecteur exact du paragraphe et une limite de 95 %, une seule ancre est reconnue ; signature, logos et mentions françaises/anglaises sont retirés. Le texte utile reste présent. Le mail original, qui contient des coordonnées personnelles, n'est pas embarqué comme fixture dans l'archive.

Version 1.1.1 : reproduction synthétique d'une signature suivie d'un `#divRplyFwdMsg` et d'un `blockquote`. L'ancienne configuration conserve l'en-tête ; l'option de suppression de l'historique le retire. Le HTML exact du nouveau mail signalé n'a pas été fourni : la reproduction établit une cause possible dans le plugin, pas un diagnostic confirmé sur ce mail ni sur le serveur.

Version 1.1.2 : tests d'écriture réelle dans un répertoire temporaire isolé, puis suppression des seuls fichiers de test. Validation du quota de 20 captures et de la continuité du nettoyage une fois le quota atteint. Les permissions Unix doivent toujours être confirmées sur le conteneur Linux cible.

Version 1.1.3 : test du POST de diagnostic avec doubles des composants GLPI. Le formulaire crée une capture fictive identifiée, affiche le chemin réel et la version, et ne persiste pas les champs du formulaire. Les 68 assertions précédentes continuent à passer. Ce test ne confirme pas les permissions ni le déclenchement du hook sur l'instance utilisateur ; le bouton sert à recueillir cette preuve localement.

Version 1.1.4 : analyse d'une vraie capture 1.1.3 transmise pour le ticket de test. Le statut était `no_match`, la règle exacte copiée du navigateur trouvait zéro ancre et `before_html` était identique à `effective_html`. L'en-tête Outlook était déjà présent dans `before_html`. La nouvelle règle trouve une ancre ; le test reproduit `ratio_guard` à 95 %, puis `cleaned` à 100 % avec conservation de `fsdfs` et retrait de la signature et de l'ancien en-tête. La capture personnelle n'est pas embarquée dans l'archive ; une variante synthétique anonymisée est ajoutée aux régressions.

## Recette avant production

Version 1.2.0 : activer les couleurs GLPI, décocher la simulation, importer un ticket et une réponse avec texte noir sur fond blanc. Les vérifier en thème sombre puis clair. Contrôler que la signature initiale est conservée, que le nettoyage des signatures des suivis reste réglé comme avant, et que liens/documents/images fonctionnent. Une saisie manuelle colorée doit rester intacte et la simulation doit conserver le corps original.

1. Sur une instance GLPI 12.0.x de recette, installer et activer le plugin. Ouvrir sa configuration avec un profil autorisé, puis vérifier qu'un profil sans droit de configuration ne peut pas y accéder directement.
2. Vérifier le démarrage désactivé / simulation activée. Tester un HTML avec votre signature ; vérifier les correspondances et le code avant/après. Tester une règle erronée : enregistrement refusé, configuration précédente conservée.
3. Tester un mail avec citation, marqueurs de réponse, accents, emoji, image et document joint. Vérifier le maintien de la réponse et des éléments protégés. Tester une réponse ne contenant que la signature : original conservé.
4. Créer un ticket manuel et un ticket par collecteur : le contenu initial ne doit pas être nettoyé par le plugin. Ajouter un suivi depuis l'interface avec les mêmes balises : il doit rester intact.
5. Activer le nettoyage, conserver la simulation, activer le journal. Répondre à une notification d'un ticket **ouvert** via la boîte IMAP de test et lancer `mailgate`. Vérifier la création d'un seul suivi, le statut du journal et la conservation de la signature en simulation.
6. Décocher la simulation et renvoyer une nouvelle réponse distincte. Vérifier que seule la signature ciblée disparaît, que le texte et les pièces jointes restent utilisables, et que la notification émise contient le suivi nettoyé.
7. Tester une signature non reconnue et une règle trop large : absence de modification ou refus attendu. Tester aussi un message en texte brut et une réponse à un ticket résolu pour vérifier le comportement natif de réouverture de votre GLPI.
8. Désactiver le plugin et refaire un cycle : comportement natif rétabli. Réactiver, redémarrer le conteneur et vérifier que les règles sont toujours présentes.
9. Sur une copie de recette, désinstaller : seules les valeurs de configuration du plugin doivent disparaître ; les tickets et suivis restent présents. Ne pas effectuer cette étape sur la production si vous souhaitez conserver vos règles.

Conserver le HTML et les messages originaux de recette dans votre espace sécurisé pour pouvoir comparer. Éviter toute règle générique sans exemples probants.
