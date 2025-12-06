# Task Completion Checklist

Before completing any task, ensure:

1. **Code Quality**
   ```bash
   make lint       # Check code style
   make analyse    # Run PHPStan (level 6)
   ```

2. **Tests**
   ```bash
   APP_ENV=test php bin/phpunit
   ```

3. **For Database Changes**
   - Create migration file in `migrations/` directory
   - Migration should be reversible where possible
   - Test migration locally

4. **For Entity Changes**
   - Update getters/setters if nullable types change
   - Consider impact on views (product_summary, device_summary)
   - Update related repositories if using raw SQL

5. **Commit Guidelines**
   - Use semantic commits
   - Example: `feat: add dark mode support`
   - Example: `fix: correct submission count for DCL imports`
