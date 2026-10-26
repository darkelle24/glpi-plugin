# Validation et recette

## Vérifié localement

- Audit des sources GLPI 12.0.0, sans modification du cœur.
- Syntaxe de tous les fichiers PHP.
- 43 assertions du lanceur `tests/run.php` : sélecteurs CSS, attributs et pseudo-classes ; UTF-8 ; absence de correspondance ; idempotence ; HTML échappé ; document complet et HTML incomplet ; suppression imbriquée ; citations ; marqueurs GLPI/personnalisés/commentaires ; pièces jointes et placeholders d'images du collecteur ; résultat vide ; limite proportionnelle ; limites de taille/nombre ; déclaration d'entité refusée ; configuration invalide ; contrat du hook avec doubles ; exclusion des saisies manuelles ; simulation ; configuration initiale et désinstallation.
- Moteur CssSelector exact du verrou GLPI 12.0.0 : Symfony 7.4.18, commit `fecf40067fc8d8880ea87b8ac227600b9aadd1d0`.
- Lanceur exécuté avec succès sur PHP **8.3.36** et **8.5.12** (Windows x64) ; contrôle de syntaxe sur PHP 8.3.36. Aucun runtime ou dépendance de test n'est embarqué dans le plugin.
- Script de déploiement : syntaxe POSIX vérifiée. La copie et la sauvegarde ont été exercées dans un dossier temporaire avec le shell Git Windows ; cette distribution ne fournit pas `chmod`, donc la vérification complète des permissions Linux reste à effectuer sur l'hôte cible. Le script final vérifie la présence des utilitaires avant de copier.

Les doubles `Config`, `ITILFollowup` et `Toolbox` ne démarrent pas GLPI et ne remplacent pas un test d'intégration. Aucune instance GLPI/MySQL/IMAP ni hôte TrueNAS n'était connecté à cet environnement. L'installation en base, le rendu réel de l'administration et un cycle IMAP complet restent à valider sur l'instance de recette. Aucun résultat de déploiement sur votre serveur n'est revendiqué.

## Recette avant production

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
