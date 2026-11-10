<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('cms_pages')) {
            Schema::create('cms_pages', function (Blueprint $table) {
                $table->id();
                $table->string('slug')->unique();
                $table->string('title');
                $table->longText('content')->nullable();
                $table->text('meta_description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            // Seed initial 6 pages required
            $now = now();
            $defaultPages = [
                [
                    'slug' => 'about',
                    'title' => 'About Us',
                    'content' => '<h2>About Yeewala</h2><p>Yeewala is an educational video learning portal built to provide students, software engineers, and technical creators with direct access to high-impact course lectures and bite-sized concept tutorials.</p>',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'slug' => 'contact',
                    'title' => 'Contact Details',
                    'content' => '<h2>Contact Yeewala Support</h2><p>For educational inquiries, support, or partnership details, please contact us at support@yeewala.com or call our educational hotline.</p>',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'slug' => 'terms',
                    'title' => 'Terms & Conditions',
                    'content' => '<h2>Terms and Conditions of Use</h2><p>By accessing Yeewala, students and educators agree to adhere to standard educational guidelines, respectful interaction, and authorized learning content access.</p>',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'slug' => 'privacy',
                    'title' => 'Privacy Policy',
                    'content' => '<h2>Privacy Policy</h2><p>Yeewala respects your privacy. All user profile data, watch history, and learning progress are safeguarded with industry-standard encryption.</p>',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'slug' => 'disclaimer',
                    'title' => 'Disclaimer',
                    'content' => '<h2>Academic Disclaimer</h2><p>All videos, courses, and educational materials provided on Yeewala are intended solely for learning and personal development purposes.</p>',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'slug' => 'copyrights',
                    'title' => 'Copyright Information',
                    'content' => '<h2>Copyright & Ownership</h2><p>Yeewala enforces strict intellectual property guidelines. All educational course creators maintain ownership of their content with full academic permission.</p>',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ];

            DB::table('cms_pages')->insert($defaultPages);
        }

        if (!Schema::hasTable('menu_items')) {
            Schema::create('menu_items', function (Blueprint $table) {
                $table->id();
                $table->string('label');
                $table->string('url');
                $table->string('icon')->nullable();
                $table->string('target')->default('_self');
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->string('location')->default('sidebar'); // 'sidebar', 'header', 'footer'
                $table->timestamps();
            });

            $now = now();
            $defaultMenus = [
                ['label' => 'Home', 'url' => '/', 'icon' => 'Home', 'sort_order' => 1, 'is_active' => true, 'location' => 'sidebar', 'created_at' => $now, 'updated_at' => $now],
                ['label' => 'Clips', 'url' => '/shorts', 'icon' => 'Sparkles', 'sort_order' => 2, 'is_active' => true, 'location' => 'sidebar', 'created_at' => $now, 'updated_at' => $now],
                ['label' => 'Trending', 'url' => '/trending', 'icon' => 'Flame', 'sort_order' => 3, 'is_active' => true, 'location' => 'sidebar', 'created_at' => $now, 'updated_at' => $now],
                ['label' => 'Mentors', 'url' => '/following', 'icon' => 'Users', 'sort_order' => 4, 'is_active' => true, 'location' => 'sidebar', 'created_at' => $now, 'updated_at' => $now],
                ['label' => 'Courses', 'url' => '/purchased-videos', 'icon' => 'GraduationCap', 'sort_order' => 5, 'is_active' => true, 'location' => 'sidebar', 'created_at' => $now, 'updated_at' => $now],
                ['label' => 'Wallet', 'url' => '/wallet', 'icon' => 'Wallet', 'sort_order' => 6, 'is_active' => true, 'location' => 'sidebar', 'created_at' => $now, 'updated_at' => $now],
                ['label' => 'Contact Us', 'url' => '/contact', 'icon' => 'Headphones', 'sort_order' => 7, 'is_active' => true, 'location' => 'sidebar', 'created_at' => $now, 'updated_at' => $now],
            ];

            DB::table('menu_items')->insert($defaultMenus);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('cms_pages');
    }
};

