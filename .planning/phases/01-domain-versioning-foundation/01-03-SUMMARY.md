---
phase: 01-domain-versioning-foundation
plan: 03
date: 2026-05-27
status: complete
requirements: [TRANSFORM-01, TRANSFORM-06]
---

# Plan 01-03 — TransformationCode constraint — SUMMARY

## Décision : constraint séparé, pas d'extension de `AppAssert\Code`

Conformément au Pitfall E du RESEARCH : `AppAssert\Code` valide le **snake_case** (`/^[a-z]+(_[a-z]+)*$/`) et est utilisé par 4+ entités (AssetFlag, AttributeDefinition, Collection, Taxonomy). Modifier cette contrainte pour qu'elle accepte le kebab-case casserait toutes ces entités. Solution : créer `AppAssert\TransformationCode` séparé, restreint à `AssetTransformation`.

## Regex finale

```
/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/
```

- Commence par une lettre minuscule.
- Segments alphanum séparés par un seul tiret.
- Pas de tirets consécutifs, pas de leading/trailing hyphen.
- ASCII-only (rejette punycode/unicode contournements).

## Codes réservés

```php
['api', 'admin', 't', '_', 'assets']
```

Justification :
- `api` : route Symfony existante `/api/*`.
- `admin` : convention future.
- `t` : préfixe public Phase 3 `/t/{code}/{id}.{ext}`.
- `_` : convention Symfony pour les routes spéciales.
- `assets` : routes statiques.

## Ordre des checks (important pour les messages)

1. **Blocklist** (priorité : `t` mono-char doit retourner "reserved", pas "invalid").
2. **Length** > 50.
3. **Mono-caractère** (length === 1).
4. **Regex** kebab-case.

## Tests (20/20 ✓)

- 5 codes valides (data provider).
- 8 codes invalides (UPPER, hero_webp, -leading, trailing-, double--hyphen, with space, > 50 chars, mono-char).
- 5 codes réservés.
- 2 cas null/empty (skip — délégué à `NotBlank`).

## Smoke-test API (matrice complète)

| Catégorie | Code | Statut HTTP |
|-----------|------|-------------|
| Valide | `hero-webp`, `thumb-product`, `p-1` | 201 |
| Réservé | `api`, `admin`, `t`, `_`, `assets` | 422 |
| Invalide | `hero_webp`, `UPPER`, `-bad`, `bad-`, `a` | 422 |

## Note pour Plan 04

La validation `TransformationCode` s'exécute AVANT `EntityManager::flush()` (chaîne Symfony Validator). Le listener `TransformationHashListener` n'aura donc jamais à gérer un code invalide — il peut assumer `getCode() !== null` et `getCode()` ∈ kebab-case valide.

## Webfacto reminder

Tout ajout futur à la blocklist (`reservedCodes`) nécessite un cadrage Webfacto pour éviter de casser les transformations déjà créées en prod.
