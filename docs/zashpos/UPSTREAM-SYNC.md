# Syncing with upstream NexoPOS

1. git fetch upstream
2. git checkout -b sync/upstream-YYYY-MM-DD master
3. git merge upstream/master
4. On conflicts keep our visible branding (ZashPOS, footer "Based on NexoPOS") and take upstream for internals.
5. Search for new user-visible "NexoPOS" strings and rebrand them.
6. Run php artisan test --compact and compare with the baseline in .cursor/rules/zashpos-workflow.mdc.
7. Enable ZashDine and run its smoke test.
8. Open a PR into master. Add a CHANGELOG.md entry.
