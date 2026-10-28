# Statuts

| Statut | Sens |
|---|---|
| `cleaned` | Suppression autorisée ; résultat appliqué sauf en simulation |
| `colors_normalized` | Couleurs du mail retirées pour hériter du thème ; `signature_status` conserve la décision sur les signatures |
| `no_match` | Aucun élément ne correspond |
| `ambiguous_cut` | Un même sélecteur de coupure trouve plusieurs repères ; original conservé |
| `invalid_cut` | Le repère de coupure n'est pas un élément à l'intérieur du corps HTML |
| `protected` | Tous les candidats sont protégés |
| `plain_text` | Aucune balise HTML détectée |
| `empty_guard` | Le résultat aurait été vide |
| `ratio_guard` | Part de texte ou de HTML supprimée supérieure à la limite |
| `marker_guard` | Nombre d'occurrences d'un marqueur modifié |
| `too_many_matches` | Plus de 100 éléments candidats |
| `too_large` | Plus de 1 Mio |
| `unsafe_declaration` | Déclaration d'entité / DTD interne refusée |
| `parse_failed` | Aucun corps HTML exploitable |
| `error` | Configuration invalide ou erreur interne, original conservé |
| `unchanged` | Valeur initiale, aucun traitement effectif |

Un refus peut avoir des correspondances et un compteur d'éléments protégés, mais `removed=0` : aucun nettoyage n'a été accepté.
