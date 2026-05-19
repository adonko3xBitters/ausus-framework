<!--
Thanks for contributing to AUSUS!

Before submitting, please ensure:
  - [ ] `bash scripts/ci.sh` passes locally (9/9 steps)
  - [ ] Commits follow Conventional Commits (see CONTRIBUTING.md §3)
  - [ ] Any new public API has a doc/RFC reference
-->

## Summary

<!-- One or two sentences describing what this PR changes and why. -->

## Type of change

- [ ] feat — new feature
- [ ] fix — bug fix
- [ ] refactor — code change with no behavior change
- [ ] perf — performance improvement
- [ ] docs — documentation only
- [ ] chore — tooling / CI / build

## Related RFCs / issues

<!-- e.g. "Implements RFC-006 §4.2" or "Closes #42" -->

## How was this tested

<!--
Describe the validation steps you ran. At minimum, list the output of:
  - bash scripts/ci.sh
  - bash scripts/clean-room.sh (if touching package metadata)
-->

```
$ bash scripts/ci.sh
...
[ci] DONE — all 9 steps passed
```

## Breaking changes

- [ ] No breaking changes
- [ ] Breaking change documented below

<!--
If breaking, include:
  - what breaks
  - migration steps
  - why we're doing it now
-->

## Checklist

- [ ] CI is green
- [ ] CHANGELOG.md updated (for the touched package, if behavior-visible)
- [ ] Public API change → RFC or design doc reference
- [ ] Commits squashable into a single Conventional Commit message
