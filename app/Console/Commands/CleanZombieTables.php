<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\RestaurantTable;
use App\Models\RestaurantOrder;

class CleanZombieTables extends Command
{
    protected $signature = 'pos:clean-zombie-tables';
    protected $description = 'Reset occupied tables that have no active orders';

    public function handle()
    {
        $zombies = RestaurantTable::where('status', 'occupied')->get();
        $cleaned = 0;

        foreach ($zombies as $table) {
            $activeOrders = RestaurantOrder::where('table_id', $table->id)
                ->whereIn('status', ['held', 'preparing', 'ready'])
                ->count();

            if ($activeOrders === 0) {
                $table->update([
                    'status' => 'available',
                    'locked_by_user_id' => null,
                    'locked_at' => null,
                ]);
                $cleaned++;
            }
        }

        $this->info("Cleaned {$cleaned} zombie tables.");
        return 0;
    }
}
