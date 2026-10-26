# Mail Signature Cleaner 1.1.2

Plugin pour **GLPI 12.0.x / PHP ≥ 8.3**, interface en français. Dossier système : `mailsignaturecleaner`.

## Fonctionnement

Le plugin retire les sous-arbres HTML correspondant à vos sélecteurs CSS des **nouveaux suivis de tickets créés par les collecteurs mail**. Il ne modifie pas le ticket initial, les suivis existants, les suivis manuels, les tâches, les solutions ni les messages SMTP sortants. Aucun changement du cœur de GLPI. Les données de configuration sont conservées dans `glpi_configs`, contexte `plugin:mailsignaturecleaner` ; aucune table supplémentaire.

Sélecteurs multiples : un par ligne, évalués sur le même document avant suppression. Les correspondances imbriquées sont dédupliquées. Le champ de suppression normale retire l'élément correspondant et ses descendants. Depuis la version 1.1.0, un champ séparé permet de **couper tout ce qui suit un repère**, même hors de son parent. Le moteur Symfony CssSelector est celui déjà fourni par GLPI ; **aucun `composer install` requis**.

## Coupure après un paragraphe (nouveau en 1.1.0)

**Correctif 1.1.1 — en-têtes de réponse qui restent affichés :** en 1.1.0, une coupure peut retirer la signature tout en conservant un bloc Outlook `#divRplyFwdMsg` ou un `blockquote` protégé. Cela laisse apparaître un ancien en-tête « De / Envoyé / À / Objet ». Le plugin ne construit pas cet en-tête, mais sa conservation partielle est reproductible.

Pour retirer aussi l'historique situé après le repère, cocher **Coupure : supprimer aussi l’historique cité et les en-têtes Outlook après le repère**. L'option est désactivée par défaut et conserve les configurations existantes. Elle lève uniquement la protection des formats de citation reconnus (`blockquote`, `.gmail_quote`, `.moz-cite-prefix`, `#divRplyFwdMsg`) pour les éléments ciblés par la coupure. Les marqueurs GLPI, pièces jointes, autres protections et limites restent actifs. Elle ne retire pas un en-tête placé avant le repère ; la suppression normale continue à protéger les citations. Un repère trouvé à l'intérieur d'une citation reste refusé pour ne pas couper depuis une ancienne signature.

Dans **Couper tout ce qui suit ce repère CSS**, placer le sélecteur du paragraphe qui précède la signature. Le repère et ses ancêtres sont conservés ; les éléments, commentaires et textes suivants dans le corps du document sont candidats à la suppression. Les protections de citations, pièces jointes et marqueurs restent prioritaires : des blocs protégés peuvent donc subsister après le repère. La limite de suppression reste appliquée.

Si un même sélecteur trouve plusieurs repères, le nettoyage entier est annulé (`ambiguous_cut`). Un repère dans une citation protégée est refusé. Un repère absent ne produit aucune coupure. Le champ reste vide par défaut et les configurations existantes sont conservées à la mise à jour.

Pour le mail d'exemple transmis (paragraphes Outlook), utiliser :

```css
p[style="font-size:11pt;font-family:Calibri,sans-serif;margin:0 0 6pt 0;line-height:12.0pt;"]
```

Régler la limite à **95 % pour cet exemple** : sa signature représente la majorité du contenu. Le test sur l'extrait fourni conserve le texte de la réponse et retire la signature ainsi que les mentions françaises et anglaises. Ne pas mettre cette règle dans le champ de suppression normale, qui ne retirerait que le paragraphe vide. Retirer les deux anciennes règles structurelles proposées pour cet exemple si vous utilisez désormais la coupure seule.

Ce style n'est pas un identifiant universel de signature : une autre mise en forme peut le modifier ou le réutiliser ailleurs. Tester les variantes réellement reçues par GLPI. Un identifiant dédié dans le modèle de signature reste préférable à long terme.

Le hook exige un `ITILFollowup`, `itemtype=Ticket`, `_mailgate>0` et `_auto_import`. Le simple type de demande « e-mail » n'est pas une preuve suffisante. Le collecteur GLPI utilise le même chemin pour IMAP et POP : le plugin s'applique aux deux, sans distinction du protocole. Les imports tiers qui reproduiraient volontairement ces champs pourraient aussi déclencher le nettoyage. Ce filtre est un ciblage fonctionnel, pas une frontière de sécurité pour une API.

## Installation

1. Copier **le dossier `mailsignaturecleaner` entier** dans le répertoire `plugins` de GLPI. Le fichier doit se trouver à `plugins/mailsignaturecleaner/setup.php`, sans dossier supplémentaire intermédiaire.
2. Dans GLPI, ouvrir **Configuration → Plugins**, installer puis activer **Mail Signature Cleaner**. Le compte doit avoir les droits de gestion des plugins. La configuration du plugin exige le droit **Configuration générale : mise à jour** (`config`, `UPDATE`).
3. Ouvrir sa configuration depuis la liste des plugins. Accès direct, à adapter au préfixe de votre GLPI : `/plugins/mailsignaturecleaner/front/config.php`.
4. Ajouter vos sélecteurs et tester plusieurs vrais corps HTML. **Tester sans enregistrer** applique les champs affichés au seul exemple. **Enregistrer** persiste la configuration.
5. Au départ, le nettoyage est **désactivé** et **en simulation**. Cocher « Activer », conserver « Simulation » et activer le journal pour observer un cycle de collecte sans modifier les messages. Puis décocher « Simulation » et enregistrer pour réellement nettoyer les prochains suivis.

## Capturer ce que reçoit le plugin (nouveau en 1.1.2)

Le journal technique habituel ne contient pas les corps des mails. Pour diagnostiquer une signature ou un en-tête conservé :

1. Installer la version 1.1.2, redémarrer le conteneur et ouvrir la configuration.
2. Cocher **Diagnostic temporaire : capturer le HTML avant/après**, enregistrer, puis envoyer une nouvelle réponse à un ticket de test et laisser le collecteur la traiter.
3. Récupérer le nouveau fichier `capture-<date UTC>-<identifiant>.json` dans `GLPI_LOG_DIR/mailsignaturecleaner-debug/`, généralement `/var/glpi/files/_log/mailsignaturecleaner-debug/` sur l'image officielle.
4. Décocher le diagnostic après la reproduction. Retirer les captures après analyse : elles contiennent les corps des messages et peuvent inclure des coordonnées et informations confidentielles.

Champs principaux du JSON :

| Champ | Contenu |
|---|---|
| `before_html` | Corps transmis au hook, avant intervention du plugin |
| `proposed_html` | Résultat calculé par le moteur de nettoyage |
| `effective_html` | Corps laissé par ce hook pour la suite de GLPI ; identique à l'entrée en simulation ou si le nettoyage est désactivé |
| `rules`, `matches` | Règles utilisées et nombre de correspondances |
| `status`, `protected`, `removed` | Décision du moteur et compteurs |
| `enabled`, `dry_run` | État du nettoyage et du mode simulation |
| `ticket_id`, `collector_id`, `captured_at_utc` | Contexte du suivi et date UTC |
| `body_limits` | Taille initiale de chaque corps et indication de troncature |

La capture fonctionne même lorsque le nettoyage est désactivé : le moteur calcule alors une proposition sans l'appliquer. Elle s'applique toujours uniquement aux suivis de tickets issus du collecteur ; elle ne capture ni nouveaux tickets, ni suivis manuels, ni tests collés dans l'administration. Elle ne dépend pas du réglage « Journal technique » ni de l'activation des journaux GLPI.

Les fichiers sont créés avec des permissions privées (dossier 0700, fichiers 0600 sous Linux). Le répertoire de logs doit rester hors de la racine web publique. Maximum **20 captures**, avec **256 Kio par version de corps** : au-delà, les captures s'arrêtent sans bloquer le collecteur. Les octets UTF-8 incomplets ou invalides sont remplacés dans le JSON. Les fichiers existants ne sont ni écrasés ni supprimés automatiquement. Pour recommencer après 20 fichiers, archiver ou retirer les anciennes captures ; l'option décochée ne supprime pas les fichiers. La désinstallation conserve également ces fichiers de diagnostic, comme les journaux GLPI.

**Ce n'est pas le mail IMAP brut** : GLPI a déjà extrait le corps MIME, traité certains marqueurs et images avant le hook. Pour comparer avec le message original complet et ses en-têtes, télécharger aussi le mail `.eml` depuis la boîte du collecteur avant son archivage/suppression. `effective_html` représente la sortie du hook ; des traitements GLPI ou d'autres plugins peuvent encore intervenir avant l'enregistrement final.

Exemples dans le terminal TrueNAS, en remplaçant le nom du conteneur :

```sh
docker exec NOM_CONTENEUR_GLPI ls -lt /var/glpi/files/_log/mailsignaturecleaner-debug
docker cp NOM_CONTENEUR_GLPI:/var/glpi/files/_log/mailsignaturecleaner-debug ./msc-debug
```

Le second exemple copie le dossier dans `msc-debug` du répertoire courant de votre terminal. Ouvrir un fichier JSON comme du texte ; ne pas publier ces captures. Le mot de passe IMAP, les pièces jointes binaires et l'objet MIME ne sont pas sérialisés par la capture.

## Exemples de règles

```css
// Classe Gmail
.gmail_signature
// Conteneur Outlook selon votre modèle
[id^="Signature"]
// Thunderbird
div.moz-signature
// Attribut, descendant, enfant direct et négation
div[data-signature="company"] > table:not(.conserver)
table.signature tr:nth-child(2)
```

Les exemples sont des règles possibles, pas une détection universelle des signatures. Utiliser le HTML réellement reçu par le collecteur, pas seulement celui du navigateur de l'expéditeur. La casse des valeurs de `class` et `id` compte.

Prise en charge : classes, identifiants, attributs (`=`, `^=`, `$=`, `*=`…), groupes avec virgule, descendants, enfants `>`, frères `+`/`~`, `:not()`, `:nth-child()` et les fonctionnalités reconnues par Symfony 7.4. Les pseudo-éléments, états de navigateur et `:has()` ne sont pas pris en charge. Les règles invalides sont refusées à l'enregistrement. Le moteur n'accepte pas d'expressions XPath libres ni de code PHP.

## Protections et limites

- Les marqueurs GLPI `=-=-=-=` et `=_=_=_=` sont toujours protégés, même s'ils sont retirés de la liste affichée. Vous pouvez ajouter vos propres marqueurs. Les candidats contenant ces marqueurs, y compris dans un commentaire HTML, sont conservés ; un contrôle final vérifie leur nombre.
- Les `blockquote` sont protégés, sauf pour une coupure avec suppression explicite de l'historique en 1.1.1. Les éléments reconnus comme pièces jointes GLPI (`document.send.php`, `data-glpi-document` et les placeholders `img` avec `id` sans `src` produits par le collecteur), leurs descendants et leurs ancêtres restent protégés. Une signature qui contient une image convertie en document GLPI peut donc être conservée par prudence : le plugin ne peut pas distinguer avec certitude un logo d'une image utile dans ce bloc.
- La liste de protection configurable complète ces protections. Par défaut : `.gmail_quote`, `.moz-cite-prefix`, `#divRplyFwdMsg`. Il n'existe pas de détection exhaustive de tous les formats de citation.
- Si le résultat n'a plus de texte ni de média/lien, le message d'origine est conservé. La limite de suppression (80 % par défaut, réglable de 1 à 95 %) s'applique au maximum de la proportion de texte retiré et de la proportion de HTML retiré. Ce garde-fou peut conserver une longue signature dans une réponse très courte.
- Maximum : 1 Mio par message, 50 sélecteurs par liste, 512 octets par sélecteur et 100 éléments candidats. En cas de dépassement, erreur, marqueur altéré ou suppression excessive, **le contenu d'origine est conservé et le suivi reste créé**.
- Le texte brut et le HTML entièrement échappé sont laissés intacts. Le plugin travaille sur le HTML après les transformations natives du collecteur : un élément déjà supprimé par GLPI ne peut pas être retrouvé. Un texte brut contenant littéralement des balises HTML peut être interprété comme du HTML ; éviter des règles génériques.
- Le parseur DOM peut normaliser les guillemets, les entités, la casse des balises ou réparer un HTML mal formé lorsqu'une suppression est appliquée. Sans modification acceptée, le contenu reste identique octet pour octet. Le plugin ne constitue pas un assainisseur HTML ; GLPI conserve son propre traitement de sécurité.
- Les sélecteurs choisis par l'administrateur restent déterminants : une règle comme `div` pourrait viser du contenu utile. Aucun algorithme ne peut garantir de reconnaître le sens du message. Utiliser des règles propres à vos signatures et examiner les résultats avant activation.

La page de test présente le **code HTML avant/après**, le nombre de correspondances par règle, les blocs supprimés, les éléments protégés et la raison d'un refus. Le code est échappé : aucun script ni pixel de suivi du mail n'est exécuté. Le texte de test n'est ni stocké en base ni écrit dans le journal par le plugin. La page exige le droit d'administration, les modifications passent par POST avec jeton de session ; les protections d'origine de GLPI 12 restent actives.

Le journal optionnel `mailsignaturecleaner.log` est dans `GLPI_LOG_DIR` (habituellement `/var/glpi/files/_log` sur l'image officielle). Il contient seulement les identifiants du ticket/collecteur, le mode, le statut et les compteurs. `removed` représente le nombre de blocs réellement retirés du résultat ; en simulation le suivi reste inchangé. Statuts détaillés : `docs/STATUS.md`.

## Docker et TrueNAS SCALE

Voir **[deploy/TRUENAS.md](deploy/TRUENAS.md)** : installation dans une application existante, script de copie depuis un dépôt Git, montages, droits, mise à jour et retour arrière. `deploy/compose.override.yaml` complète votre service existant ; `deploy/compose.lab.yaml` sert uniquement à créer une instance de recette indépendante.

## Tests et recette

Dans le conteneur GLPI officiel, après copie du plugin :

```sh
GLPI_AUTOLOAD=/var/www/glpi/vendor/autoload.php php /var/www/glpi/plugins/mailsignaturecleaner/tests/run.php
```

Ce lanceur utilise l'autoload des dépendances, **sans démarrer GLPI**, et des doubles pour vérifier le contrat du hook. Il n'écrit pas dans votre base. En développement, `CSS_SELECTOR_SOURCE` peut désigner les sources de Symfony CssSelector à la place.

Consulter **[docs/VALIDATION.md](docs/VALIDATION.md)** pour les vérifications effectuées et la recette IMAP indispensable sur votre instance. Les hooks et méthodes sont vérifiés contre GLPI **12.0.0**, commit `cd812fbc82eaa8d0356d0fb650842069c1c18f4e` : voir **[docs/SOURCES.md](docs/SOURCES.md)**. La compatibilité 12.0.x est déclarée, mais chaque nouvelle version doit passer la recette. GLPI 10, 11 et 12.1 sont exclus.

## Désactivation / désinstallation

Décocher « Activer » conserve les règles et arrête les suppressions. Désactiver le plugin dans GLPI arrête son hook. Désinstaller supprime seulement ses valeurs de configuration ; les suivis déjà nettoyés ne sont pas restaurés. Conserver les messages IMAP ou une sauvegarde si vous souhaitez pouvoir retrouver leurs signatures. Les pièces jointes ne sont jamais supprimées de la base ou du disque par le plugin.

Licence : GPL-3.0-or-later ; voir `LICENSE`.
