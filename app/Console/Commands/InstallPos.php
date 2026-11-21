<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\RoleAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InstallPos extends Command
{
    protected $signature = 'pos:install';
    protected $description = 'Buat bisnis, outlet, dan owner POS pertama';

    public function handle(): int
    {
        $name = $this->ask('Nama bisnis'); $outlet = $this->ask('Nama outlet pertama'); $email = $this->ask('Email owner'); $password = $this->secret('Kata sandi owner');
        if (!$name || !$outlet || !$email || !$password || strlen($password) < 8) { $this->error('Semua isian wajib; kata sandi minimal 8 karakter.'); return self::FAILURE; }
        if (User::where('email', $email)->exists()) { $this->error('Email sudah digunakan.'); return self::FAILURE; }
        DB::transaction(function () use ($name, $outlet, $email, $password) {
            $business = DB::table('businesses')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            RoleAccess::seedDefaults($business);
            $outletId = DB::table('outlets')->insertGetId(['business_id' => $business, 'name' => $outlet, 'code' => 'UTAMA', 'created_at' => now(), 'updated_at' => now()]);
            $user = new User; $user->name = 'Owner'; $user->email = $email; $user->password = $password; $user->business_id = $business; $user->outlet_id = $outletId; $user->role = 'owner'; $user->save();
            DB::table('user_outlets')->insert(['user_id' => $user->id, 'outlet_id' => $outletId]);
        });
        $this->info('POS siap. Masuk dengan email owner.'); return self::SUCCESS;
    }
}
