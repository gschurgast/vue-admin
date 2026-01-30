# Documentation des Definitions d'Attributs

## Vue d'ensemble

La ressource Definition d'Attribut definit la structure et le comportement des attributs produit dans votre systeme PIM (Product Information Management). Chaque definition d'attribut decrit comment une donnee produit specifique doit etre stockee, validee et affichee.

## Champs

### Code
- **Type** : Chaine de caracteres (max 50 caracteres, unique)
- **Obligatoire** : Oui
- **Description** : Un identifiant unique pour l'attribut, utilise de maniere programmatique. Doit suivre la convention camelCase ou snake_case.
- **Exemple** : `colorMarketing`, `weight_kg`, `description`

### Type
- **Type** : Enumeration
- **Obligatoire** : Oui
- **Description** : Definit comment la valeur de l'attribut est stockee et affichee. Voir [Types d'attributs](#types-dattributs) ci-dessous.

### Est Localisable
- **Type** : Booleen
- **Defaut** : false
- **Description** : Lorsqu'active, cet attribut peut avoir des valeurs differentes par locale (langue/region). A utiliser pour le contenu traduisible comme les descriptions, noms ou textes marketing.
- **Exemple** : Une description produit necessitant des versions francaise, anglaise et allemande.

### Est Scopable
- **Type** : Booleen
- **Defaut** : false
- **Description** : Lorsqu'active, cet attribut peut avoir des valeurs differentes par marche/canal. A utiliser pour les donnees specifiques au marche comme les niveaux de prix ou specifications regionales.
- **Exemple** : Specifications produit differentes pour les marches UE vs US.

### Est Obligatoire
- **Type** : Booleen
- **Defaut** : false
- **Description** : Lorsqu'active, une valeur doit etre fournie pour cet attribut lors de l'enregistrement d'un produit.

### Regles de Validation
- **Type** : JSON (paires cle-valeur)
- **Obligatoire** : Non
- **Description** : Contraintes de validation personnalisees pour la valeur de l'attribut.
- **Regles courantes** :
  - `minLength` : Nombre minimum de caracteres pour le texte
  - `maxLength` : Nombre maximum de caracteres pour le texte
  - `min` : Valeur minimale pour les nombres
  - `max` : Valeur maximale pour les nombres
  - `pattern` : Expression reguliere pour la validation du texte

### Valeurs Autorisees
- **Type** : JSON (paires cle-valeur)
- **Obligatoire** : Non
- **Description** : Pour les enumerations simples, definit la liste des valeurs acceptables sans necessiter d'entites AttributeOption separees.

### Unite
- **Type** : Chaine de caracteres (max 50 caracteres)
- **Obligatoire** : Non
- **Description** : L'unite de mesure pour les attributs de type MEASURE.
- **Exemple** : `cm`, `kg`, `W`, `dB`, `L`

### Valeur par Defaut
- **Type** : Chaine de caracteres (max 255 caracteres)
- **Obligatoire** : Non
- **Description** : La valeur par defaut pre-remplie lors de la creation d'une nouvelle valeur d'attribut produit.

### Texte d'Aide
- **Type** : Texte
- **Obligatoire** : Non
- **Description** : Texte d'orientation affiche aux utilisateurs lors de l'edition de cet attribut. Utilisez-le pour expliquer le format attendu ou fournir des exemples.

### Ordre de Tri
- **Type** : Entier
- **Defaut** : 0
- **Description** : Controle l'ordre d'affichage des attributs dans les formulaires et listes. Les valeurs inferieures apparaissent en premier.

## Types d'Attributs

- **text** : Texte court simple, non formate. Exemples : "Chene", "Noir", "Modele X"
- **textarea** : Texte multiligne brut. Exemples : "Assemblage requis. Livre en 2 colis."
- **richtext** : Texte riche avec formatage HTML. Exemples : Descriptions marketing avec gras, listes, liens
- **number** : Nombre a virgule flottante. Exemples : 160.5, 12.3, 99.99
- **integer** : Nombre entier. Exemples : 4, 220, 1000
- **decimal** : Decimal haute precision. Exemples : 0.00001, 199.99
- **boolean** : Bascule Vrai/Faux. Exemples : Assemblage requis : Oui/Non
- **enum** : Valeur unique d'une liste predefinie. Exemples : Couleur : "Rouge" (parmi Rouge, Bleu, Vert)
- **multienum** : Valeurs multiples d'une liste predefinie. Exemples : Tags : ["eco", "exterieur"]
- **media** : Reference fichier (image, PDF, video). Exemples : manuel_produit.pdf
- **relation** : Reference vers une autre entite. Exemples : Produit parent, Collection
- **json** : Donnees structurees complexes. Exemples : Donnees fournisseur personnalisees
- **measure** : Valeur avec unite. Exemples : 230 cm, 12 kg, 650 W

## Bonnes Pratiques

1. **Nommage du code** : Utilisez des noms descriptifs et coherents. Prefixez les attributs lies (ex: `dimension_largeur`, `dimension_hauteur`, `dimension_profondeur`).

2. **Localisation** : N'activez `isLocalizable` que pour le contenu reellement traduisible. Les specifications techniques n'ont generalement pas besoin de localisation.

3. **Scopabilite** : Utilisez `isScopable` avec parcimonie. La plupart des attributs doivent avoir des valeurs coherentes entre les marches.

4. **Selection du type** :
   - Utilisez `measure` au lieu de `number` + champ unite separe
   - Utilisez `enum`/`multienum` avec AttributeOptions pour les valeurs necessitant des traductions
   - Utilisez `allowedValues` pour les enumerations simples non traduisibles

5. **Validation** : Definissez toujours des regles de validation appropriees pour assurer la qualite des donnees.

## Ressources Associees

- **AttributeOption** : Definit les valeurs selectionnables pour les attributs de type `enum` et `multienum`
- **ProductAttributeValue** : Stocke les valeurs d'attributs reelles pour les produits
