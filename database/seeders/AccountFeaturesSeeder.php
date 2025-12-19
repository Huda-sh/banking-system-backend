<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AccountFeature;

class AccountFeaturesSeeder extends Seeder
{
    public function run()
    {
        AccountFeature::create([
            'class_name' => 'App\Accounts\Features\OverdraftFeature',
            'label' => 'Overdraft Protection'
        ]);

        AccountFeature::create([
            'class_name' => 'App\Accounts\Features\InternationalTransferFeature',
            'label' => 'International Transfers'
        ]);

        AccountFeature::create([
            'class_name' => 'App\Accounts\Features\PremiumServicesFeature',
            'label' => 'Premium Services'
        ]);

        AccountFeature::create([
            'class_name' => 'App\Accounts\Features\HighDailyLimitFeature',
            'label' => 'High Daily Limit'
        ]);

        AccountFeature::create([
            'class_name' => 'App\Accounts\Features\NoFeesFeature',
            'label' => 'No Monthly Fees'
        ]);
    }
}
