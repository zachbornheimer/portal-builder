#!/usr/bin/env zsh
cd "${0:A:h:h:h}"
PROMPT="$(cat docs/design/claude-design-brief.md)"
op run --no-masking --env-file="$HOME/.config/claude/keys.env.op" -- \
	claude --remote-control --chrome --dangerously-skip-permissions -p "$PROMPT" \
	2>&1 | tee docs/design/claude-design-run.log
print -- "EXIT:${pipestatus[1]}" | tee -a docs/design/claude-design-run.log
