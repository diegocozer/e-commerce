<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Events\AdminRolesChanged;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Support\Guardrails;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Domain\ActorRef;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Panel users (API.md §3.G.12): invitation, edit, role sync, (de)activation, soft delete. All audited. */
final class ManageAdminUsers
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AdminPasswordResets $resets,
    ) {}

    /** @param  array{name:string, email:string, roles:list<string>}  $data */
    public function create(AdminUser $actor, array $data): AdminUser
    {
        Guardrails::assertCanAssignRoles($actor, $data['roles']);

        $admin = DB::transaction(function () use ($actor, $data): AdminUser {
            $admin = new AdminUser([
                'name' => trim($data['name']),
                'email' => $data['email'],
                'password' => Str::password(40),
            ]);
            $admin->is_active = true;
            $admin->save();
            $admin->syncRoles($data['roles']);

            $this->audit->record(new AuditEntry(ActorRef::admin($actor->id), 'admin_user.created', 'admin_user', $admin->id, null, [
                'name' => $admin->name, 'email' => $admin->email, 'roles' => $data['roles'],
            ]));

            return $admin;
        });

        event(new AdminRolesChanged($admin->id, $data['roles'], $actor->id));
        $this->resets->sendFor($admin);

        return $admin->refresh();
    }

    /** @param  array{name?:string, email?:string, roles?:list<string>}  $data */
    public function update(AdminUser $actor, AdminUser $admin, array $data): AdminUser
    {
        Guardrails::assertCanManage($actor, $admin);

        $newRoles = $data['roles'] ?? null;
        $oldRoles = $admin->getRoleNames()->sort()->values()->all();
        $rolesChanged = $newRoles !== null && collect($newRoles)->sort()->values()->all() !== $oldRoles;

        if ($rolesChanged) {
            Guardrails::assertNotSelf($actor, $admin, 'Você não pode alterar os próprios papéis.');
            $added = array_values(array_diff($newRoles, $oldRoles));
            $removed = array_values(array_diff($oldRoles, $newRoles));
            Guardrails::assertCanAssignRoles($actor, [...$added, ...$removed]);
            if (in_array('super-admin', $removed, true)) {
                Guardrails::assertKeepsASuperAdmin($admin);
            }
        }

        DB::transaction(function () use ($actor, $admin, $data, $rolesChanged, $newRoles, $oldRoles): void {
            $before = $admin->only(['name', 'email']);
            if (isset($data['name'])) {
                $admin->name = trim($data['name']);
            }
            if (isset($data['email'])) {
                $admin->email = $data['email'];
            }
            $admin->save();

            $old = $before;
            $new = $admin->only(['name', 'email']);
            if ($rolesChanged) {
                $admin->syncRoles($newRoles);
                $old['roles'] = $oldRoles;
                $new['roles'] = array_values($newRoles);
            }
            $entry = AuditEntry::diff(ActorRef::admin($actor->id), 'admin_user.updated', 'admin_user', $admin->id, $old, $new);
            if ($entry->newValues !== null) {
                $this->audit->record($entry);
            }
        });

        if ($rolesChanged) {
            event(new AdminRolesChanged($admin->id, array_values($newRoles), $actor->id));
        }

        return $admin->refresh();
    }

    public function setActive(AdminUser $actor, AdminUser $admin, bool $active): AdminUser
    {
        if (! $active) {
            Guardrails::assertNotSelf($actor, $admin, 'Você não pode desativar a si mesmo.');
        }
        Guardrails::assertCanManage($actor, $admin);
        if (! $active) {
            Guardrails::assertKeepsASuperAdmin($admin);
        }

        if ($admin->is_active !== $active) {
            $admin->is_active = $active;
            if (! $active) {
                $admin->setRememberToken(Str::random(60));
            }
            $admin->save();
            $this->audit->record(new AuditEntry(
                ActorRef::admin($actor->id), $active ? 'admin_user.activated' : 'admin_user.deactivated', 'admin_user', $admin->id,
                ['is_active' => ! $active], ['is_active' => $active],
            ));
        }

        return $admin->refresh();
    }

    public function delete(AdminUser $actor, AdminUser $admin): void
    {
        Guardrails::assertNotSelf($actor, $admin, 'Você não pode excluir a si mesmo.');
        Guardrails::assertCanManage($actor, $admin);
        Guardrails::assertKeepsASuperAdmin($admin);

        DB::transaction(function () use ($actor, $admin): void {
            $admin->setRememberToken(Str::random(60));
            $admin->save();
            $admin->delete();
            $this->audit->record(new AuditEntry(ActorRef::admin($actor->id), 'admin_user.deleted', 'admin_user', $admin->id));
        });
    }

    public function sendPasswordReset(AdminUser $actor, AdminUser $admin): void
    {
        Guardrails::assertCanManage($actor, $admin);
        $this->resets->sendFor($admin);
        $this->audit->record(new AuditEntry(ActorRef::admin($actor->id), 'admin_user.password_reset_sent', 'admin_user', $admin->id));
    }
}
