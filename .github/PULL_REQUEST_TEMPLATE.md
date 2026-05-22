# Pull Request

## Summary

<!--
What does this PR do and why? Keep it focused — one logical change per PR.
Reviewers should be able to understand the intent in 30 seconds.
-->

## Related issue

<!-- Closes #123 / References #456 -->

## Type of change

<!-- Check all that apply. -->

- [ ] Bug fix (non-breaking change that fixes an issue)
- [ ] New feature (non-breaking change that adds functionality)
- [ ] Breaking change (fix or feature that would cause existing code to change behaviour)
- [ ] Documentation update
- [ ] Internal refactor (no behaviour change)
- [ ] Test-only change

## Testing

<!--
Describe how you tested this change. New behaviour requires new tests — point
to the test file(s) and explain the cases covered.
-->

- Test files added or modified:
- Scenarios covered:

## Checklist

- [ ] `composer phpstan` passes (PHPStan level 9, zero errors).
- [ ] `composer test` passes (full PHPUnit suite).
- [ ] `composer cs-fix` has been run.
- [ ] [`CHANGELOG.md`](../CHANGELOG.md) has been updated (if the change is user-facing).
- [ ] [`UPGRADING.md`](../UPGRADING.md) has been updated (if the change is breaking).
- [ ] Relevant docs under `docs/` have been updated (if behaviour changed).
- [ ] New public APIs have PHPDoc covering parameters, return types, and thrown exceptions.

## Notes for reviewers

<!--
Anything reviewers should pay particular attention to — tricky logic, an
intentional deviation from existing patterns, a known follow-up, etc.
-->
