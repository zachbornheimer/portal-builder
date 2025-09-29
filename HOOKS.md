# Git Hooks

This project includes a pre-commit hook that automatically runs PHPCS fix on staged PHP files.

## Pre-commit Hook

The pre-commit hook (`/.git/hooks/pre-commit`) automatically:

1. **Detects staged PHP files** - Only processes files that are staged for commit
2. **Runs PHPCBF** - Automatically fixes coding standards issues
3. **Re-stages fixed files** - Adds the corrected files back to the staging area
4. **Validates results** - Runs a final PHPCS check to ensure all issues are resolved

### Features

- ✅ **Automatic fixing** - Fixes most coding standards issues automatically
- ✅ **Only staged files** - Only processes files you're about to commit
- ✅ **Re-staging** - Automatically adds fixed files back to staging
- ✅ **Validation** - Ensures all issues are resolved before allowing commit
- ✅ **Colored output** - Clear feedback on what's happening

### Requirements

- PHPCS and PHPCBF must be installed (via Composer or globally)
- The `phpcs.xml` configuration file must be present

### Installation

The hook is automatically installed when you clone the repository. If you need to reinstall it:

```bash
# Make sure the hook is executable
chmod +x .git/hooks/pre-commit
```

### Testing the Hook

You can test the hook manually:

```bash
# Run the test script
./test-hook.sh

# Or run the hook directly
./.git/hooks/pre-commit
```

### How It Works

1. When you run `git commit`, the pre-commit hook is triggered
2. The hook identifies all staged PHP files
3. PHPCBF runs on those files to fix coding standards issues
4. Any files that were modified are automatically re-staged
5. A final PHPCS check ensures no issues remain
6. If all checks pass, the commit proceeds; otherwise, it's blocked

### Troubleshooting

If the hook fails:

1. **PHPCS not found** - Install PHPCS: `composer require --dev squizlabs/php_codesniffer`
2. **Permission denied** - Make sure the hook is executable: `chmod +x .git/hooks/pre-commit`
3. **Manual fixes needed** - Some issues can't be auto-fixed; the hook will show which files need manual attention

### Bypassing the Hook

If you need to bypass the hook (not recommended):

```bash
git commit --no-verify
```

**Note**: This should only be used in exceptional circumstances as it bypasses code quality checks.
