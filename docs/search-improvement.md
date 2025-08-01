# Proposed Search Improvement

Firefly III's search currently relies on exact matches or specific operators like `*_contains` and `*_starts`.
A common user request is the ability to find transactions when typos or slight spelling variations occur in descriptions or notes.

## Idea

Introduce fuzzy search operators that match text approximately. New operators:

- `description_fuzzy`
- `notes_fuzzy`

These would use a similarity comparison (for example Levenshtein distance) with a small threshold to match near matches.

## Benefits

- Users can find transactions even with minor typos or spelling variations.
- Reduces frustration when searching for known data that was entered with small mistakes.

## Possible implementation steps

1. Extend `config/search.php` with the new operators.
2. Update `QueryParser` and `OperatorQuerySearch` to recognize and handle the fuzzy operators.
3. Add unit tests to verify behaviour of fuzzy matching.

This document serves as a proposal for discussion before implementing the feature.
