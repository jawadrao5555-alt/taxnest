<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Company;
use Illuminate\Support\Facades\Hash;

class TestUsersSeeder extends Seeder
{
    public function run(): void
    {
        // Create Company
        $company = Company::firstOrCreate([
            'name' => 'Test Company',
        ], [
            'ntn' => '1234567-8',
            'email' => 'test@company.com',
            'phone' => '03000000000',
            'address' => 'Test Address',
            'fbr_token' => 'dummy-token'
        ]);

        // Admin User
        User::updateOrCreate(
            ['email' => 'admin'],
            [
                'name' => 'Admin',
                'password' => Hash::make('admin123'),
                'role' => 'super_admin',
                'company_id' => $company->id,
            ]
        );

        // Normal User
        User::updateOrCreate(
            ['email' => 'jawad'],
            [
                'name' => 'Jawad',
                'password' => Hash::make('jawad123'),
                'role' => 'employee',
                'company_id' => $company->id,
            ]
        );
    }
}
