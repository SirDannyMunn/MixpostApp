---
applyTo: '**'
---
## MixpostApp: Tinker debugging

When you need to run ad-hoc Laravel code for debugging/inspection, **do not** use `php artisan tinker --execute` (or any `tinker-execute` style approach).

Use the local `laundryos/tinker-debug` package instead.

### How to run code

1. Create a PHP script under the project root directory:

	 - `Scratch/<script_name>.php`

2. Execute it with:

	 - `php artisan tinker-debug:run <script_name>`

Notes:
- Pass the script name without the `.php` extension.
- Scripts can be plain PHP files (just `echo`, queries, etc.).
- Class-based scripts are also supported if the class is in the `Scratch` namespace and has a `run()` method.

### Example

- File: `Scratch/hello_world.php`
	```php
	<?php
	echo "Hello World\n";
	```

- Run:
	```bash
	php artisan tinker-debug:run hello_world
	```