<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\State;
use Illuminate\Database\Seeder;

class CountryStateSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            [
                'name' => 'India',
                'code' => 'IN',
                'phone_code' => '+91',
                'states' => [
                    ['name' => 'Tamil Nadu', 'code' => 'TN'],
                    ['name' => 'Kerala', 'code' => 'KL'],
                    ['name' => 'Karnataka', 'code' => 'KA'],
                    ['name' => 'Andhra Pradesh', 'code' => 'AP'],
                    ['name' => 'Telangana', 'code' => 'TG'],
                    ['name' => 'Maharashtra', 'code' => 'MH'],
                    ['name' => 'Delhi', 'code' => 'DL'],
                    ['name' => 'Gujarat', 'code' => 'GJ'],
                    ['name' => 'Uttar Pradesh', 'code' => 'UP'],
                    ['name' => 'West Bengal', 'code' => 'WB'],
                    ['name' => 'Rajasthan', 'code' => 'RJ'],
                    ['name' => 'Punjab', 'code' => 'PB'],
                    ['name' => 'Haryana', 'code' => 'HR'],
                    ['name' => 'Madhya Pradesh', 'code' => 'MP'],
                    ['name' => 'Bihar', 'code' => 'BR'],
                    ['name' => 'Odisha', 'code' => 'OR'],
                    ['name' => 'Assam', 'code' => 'AS'],
                    ['name' => 'Goa', 'code' => 'GA'],
                ],
            ],
            [
                'name' => 'United States',
                'code' => 'US',
                'phone_code' => '+1',
                'states' => [
                    ['name' => 'California', 'code' => 'CA'],
                    ['name' => 'Texas', 'code' => 'TX'],
                    ['name' => 'Florida', 'code' => 'FL'],
                    ['name' => 'New York', 'code' => 'NY'],
                    ['name' => 'Illinois', 'code' => 'IL'],
                    ['name' => 'Pennsylvania', 'code' => 'PA'],
                    ['name' => 'Ohio', 'code' => 'OH'],
                    ['name' => 'Georgia', 'code' => 'GA'],
                    ['name' => 'North Carolina', 'code' => 'NC'],
                    ['name' => 'Washington', 'code' => 'WA'],
                ],
            ],
            [
                'name' => 'United Kingdom',
                'code' => 'GB',
                'phone_code' => '+44',
                'states' => [
                    ['name' => 'England', 'code' => 'ENG'],
                    ['name' => 'Scotland', 'code' => 'SCT'],
                    ['name' => 'Wales', 'code' => 'WLS'],
                    ['name' => 'Northern Ireland', 'code' => 'NIR'],
                ],
            ],
            [
                'name' => 'Canada',
                'code' => 'CA',
                'phone_code' => '+1',
                'states' => [
                    ['name' => 'Ontario', 'code' => 'ON'],
                    ['name' => 'Quebec', 'code' => 'QC'],
                    ['name' => 'British Columbia', 'code' => 'BC'],
                    ['name' => 'Alberta', 'code' => 'AB'],
                    ['name' => 'Manitoba', 'code' => 'MB'],
                    ['name' => 'Nova Scotia', 'code' => 'NS'],
                ],
            ],
            [
                'name' => 'Australia',
                'code' => 'AU',
                'phone_code' => '+61',
                'states' => [
                    ['name' => 'New South Wales', 'code' => 'NSW'],
                    ['name' => 'Victoria', 'code' => 'VIC'],
                    ['name' => 'Queensland', 'code' => 'QLD'],
                    ['name' => 'Western Australia', 'code' => 'WA'],
                    ['name' => 'South Australia', 'code' => 'SA'],
                    ['name' => 'Tasmania', 'code' => 'TAS'],
                ],
            ],
            [
                'name' => 'United Arab Emirates',
                'code' => 'AE',
                'phone_code' => '+971',
                'states' => [
                    ['name' => 'Dubai', 'code' => 'DXB'],
                    ['name' => 'Abu Dhabi', 'code' => 'AUH'],
                    ['name' => 'Sharjah', 'code' => 'SHJ'],
                    ['name' => 'Ajman', 'code' => 'AJM'],
                    ['name' => 'Ras Al Khaimah', 'code' => 'RAK'],
                    ['name' => 'Fujairah', 'code' => 'FUJ'],
                ],
            ],
            [
                'name' => 'Singapore',
                'code' => 'SG',
                'phone_code' => '+65',
                'states' => [
                    ['name' => 'Central Region', 'code' => 'CR'],
                    ['name' => 'East Region', 'code' => 'ER'],
                    ['name' => 'North Region', 'code' => 'NR'],
                    ['name' => 'North-East Region', 'code' => 'NER'],
                    ['name' => 'West Region', 'code' => 'WR'],
                ],
            ],
            [
                'name' => 'Malaysia',
                'code' => 'MY',
                'phone_code' => '+60',
                'states' => [
                    ['name' => 'Kuala Lumpur', 'code' => 'KUL'],
                    ['name' => 'Selangor', 'code' => 'SGR'],
                    ['name' => 'Penang', 'code' => 'PNG'],
                    ['name' => 'Johor', 'code' => 'JHR'],
                    ['name' => 'Perak', 'code' => 'PRK'],
                    ['name' => 'Sabah', 'code' => 'SBH'],
                    ['name' => 'Sarawak', 'code' => 'SWK'],
                ],
            ],
            [
                'name' => 'Germany',
                'code' => 'DE',
                'phone_code' => '+49',
                'states' => [
                    ['name' => 'Bavaria', 'code' => 'BY'],
                    ['name' => 'Berlin', 'code' => 'BE'],
                    ['name' => 'Hamburg', 'code' => 'HH'],
                    ['name' => 'Hesse', 'code' => 'HE'],
                    ['name' => 'North Rhine-Westphalia', 'code' => 'NW'],
                    ['name' => 'Baden-Württemberg', 'code' => 'BW'],
                ],
            ],
            [
                'name' => 'Sri Lanka',
                'code' => 'LK',
                'phone_code' => '+94',
                'states' => [
                    ['name' => 'Western Province', 'code' => 'WP'],
                    ['name' => 'Central Province', 'code' => 'CP'],
                    ['name' => 'Southern Province', 'code' => 'SP'],
                    ['name' => 'Northern Province', 'code' => 'NP'],
                    ['name' => 'Eastern Province', 'code' => 'EP'],
                ],
            ],
        ];

        foreach ($data as $item) {
            $states = $item['states'];
            unset($item['states']);

            $country = Country::firstOrCreate(
                ['code' => $item['code']],
                [
                    'name' => $item['name'],
                    'phone_code' => $item['phone_code'],
                    'is_active' => true,
                ]
            );

            foreach ($states as $st) {
                State::firstOrCreate(
                    [
                        'country_id' => $country->id,
                        'name' => $st['name'],
                    ],
                    [
                        'code' => $st['code'],
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
