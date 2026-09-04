# Contributing

Thank you for considering contributing to Laravel FlowField! We welcome contributions from everyone.

## Ways to Contribute

- **Report bugs** - If you find a bug, please create an issue with a clear description and steps to reproduce
- **Suggest features** - Have an idea? Open an issue to discuss it
- **Submit pull requests** - Code contributions are always welcome
- **Improve documentation** - Help make our docs clearer and more comprehensive
- **Share examples** - Show us how you're using the package

## Development Setup

1. Fork the repository
2. Clone your fork:
   ```bash
   git clone https://github.com/schtzie/laravel-flowfield.git
   cd laravel-flowfield
   ```

3. Install dependencies:
   ```bash
   composer install
   ```

4. Create a branch:
   ```bash
   git checkout -b feature/your-feature-name
   ```

## Development Workflow

### Running Tests

```bash
composer test
```

Please add tests for any new features or bug fixes. All pull requests must have passing tests.

### Code Style

We follow PSR-12 / Laravel coding standards. Run Laravel Pint to format your code:

```bash
vendor/bin/pint
```

All pull requests must pass Pint checks.

### Testing Your Changes

To test changes in a real Laravel application, add a path repository to your test app's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-flowfield"
        }
    ]
}
```

Then require the package:

```bash
composer require schtzie/laravel-flowfield:@dev
```

## Pull Request Guidelines

1. **Keep changes focused** - One feature or fix per PR
2. **Follow existing patterns** - Match the codebase style and architecture
3. **Update documentation** - Add/update README.md if needed
4. **Add tests** - Cover new functionality with tests
5. **Run code style** - Execute `vendor/bin/pint` before committing
6. **Write clear commits** - Use descriptive commit messages

### Commit Message Format

We prefer clear, descriptive commit messages:

```
Add support for queued cache invalidation

- Dispatch invalidation as a background job when queue is configured
- Fall back to synchronous invalidation when no queue is available
- Add tests for both sync and async paths
```

### Pull Request Process

1. **Create an issue first** (for major changes) - Discuss the approach before coding
2. **Update the README** if you're adding features
3. **Ensure all tests pass**
4. **Update CHANGELOG.md** with your changes (under "Unreleased")
5. **Request a review** from maintainers

## Reporting Bugs

When reporting bugs, please include:

- **Clear title** - Summarize the issue
- **PHP/Laravel versions** - Help us reproduce your environment
- **Cache driver** - Which driver are you using (Redis, Memcached, etc.)
- **Steps to reproduce** - Detailed steps to trigger the bug
- **Expected behavior** - What should happen
- **Actual behavior** - What actually happens
- **Code samples** - Minimal code to reproduce the issue

### Example Bug Report

```markdown
**Bug:** FlowField returns null after cache invalidation with Redis

**Environment:**
- PHP 8.3
- Laravel 12.0
- Cache driver: Redis
- laravel-flowfield 1.0.0

**Steps to reproduce:**
1. Define a sum FlowField on Customer
2. Access $customer->balance (caches the value)
3. Create a new entry for that customer
4. Access $customer->balance again — returns null instead of recalculating

**Expected:** Returns the updated sum
**Actual:** Returns null
```

## Feature Requests

We love hearing your ideas! When suggesting features:

- **Explain the use case** - Why is this needed?
- **Describe the API** - How should it work?
- **Consider alternatives** - Are there other solutions?
- **Check existing issues** - Has this been suggested before?

## Code of Conduct

### Our Pledge

We are committed to providing a welcoming and inclusive experience for everyone, regardless of:

- Age, body size, disability, ethnicity, gender identity and expression
- Level of experience, education, socio-economic status
- Nationality, personal appearance, race, religion
- Sexual identity and orientation

### Our Standards

**Positive behavior:**
- Using welcoming and inclusive language
- Being respectful of differing viewpoints and experiences
- Gracefully accepting constructive criticism
- Focusing on what is best for the community
- Showing empathy towards other community members

**Unacceptable behavior:**
- Trolling, insulting/derogatory comments, and personal or political attacks
- Public or private harassment
- Publishing others' private information without permission
- Other conduct which could reasonably be considered inappropriate

### Enforcement

Maintainers have the right to remove, edit, or reject comments, commits, code, issues, and other contributions that don't align with this Code of Conduct.

## Questions?

- **Documentation** - Check the [README.md](README.md) first
- **Issues** - Search existing issues before creating a new one
- **Discussions** - Use GitHub Discussions for general questions
- **Email** - Reach out to hello@openplain.dev for private inquiries

## Recognition

Contributors will be recognized in:
- Release notes for their contributions
- The README credits section (for significant contributions)

Thank you for helping make Laravel FlowField better!
