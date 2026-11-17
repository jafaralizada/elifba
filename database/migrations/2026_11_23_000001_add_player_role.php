<?php

use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/*
 * The player role (docs/architecture.md §12 "The area and the switch", §7
 * "Agreements", ADR 019): the moment an account accepted its sentence and
 * the third `roles` row. Nobody gets the role here: an account takes it
 * itself, with its sentence. Both exist whether the rooms area is switched
 * on or not. Query builder only.
 */
return new class extends Migration
{
    /** The guard the roles belong to: the accounts' session guard. */
    private const GUARD = 'web';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('player_agreed_at')->nullable()->after('parent_agreed_at');
        });

        $now = now();

        DB::table('roles')->insert([
            'name' => Role::Player->value,
            'guard_name' => self::GUARD,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $role = DB::table('roles')
            ->where('name', Role::Player->value)
            ->where('guard_name', self::GUARD)
            ->value('id');

        if ($role !== null) {
            DB::table('model_has_roles')->where('role_id', $role)->delete();
            DB::table('roles')->where('id', $role)->delete();
        }

        // An account in the player area goes back to its first role's.
        DB::table('users')->where('area', Role::Player->value)->update(['area' => null]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('player_agreed_at');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
