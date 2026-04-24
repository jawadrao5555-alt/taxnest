#!/usr/bin/env bash
echo "================================================="
echo "ACTUAL DB Laravel is using (runtime config):"
echo "================================================="
php artisan tinker --execute "echo 'CONNECTION: '.config('database.default').PHP_EOL; \$c=config('database.default'); echo 'HOST: '.config(\"database.connections.\$c.host\").PHP_EOL; echo 'PORT: '.config(\"database.connections.\$c.port\").PHP_EOL; echo 'DATABASE: '.config(\"database.connections.\$c.database\").PHP_EOL; echo 'USERNAME: '.config(\"database.connections.\$c.username\").PHP_EOL;"

echo ""
echo "================================================="
echo "Test live DB connection (count companies):"
echo "================================================="
php artisan tinker --execute "echo 'Total companies: '.\App\Models\Company::count().PHP_EOL; echo 'Total invoices: '.\App\Models\Invoice::count().PHP_EOL; echo 'ZIA exists: '.(\App\Models\Company::where('id',7)->exists() ? 'YES' : 'NO').PHP_EOL;"

echo ""
echo "================================================="
echo "Latest 3 invoices on live DB:"
echo "================================================="
php artisan tinker --execute "\App\Models\Invoice::latest('id')->take(3)->get(['id','company_id','invoice_number','status','created_at'])->each(fn(\$i) => print_r(\$i->toArray()));"
