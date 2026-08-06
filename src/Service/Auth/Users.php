<?php
declare(strict_types=1);

namespace App\Service\Auth;

use App\Db;
use App\Settings;

/**
 * Sign-in accounts and what each one is allowed to see.
 *
 * The app used to hold exactly one login in app_settings (auth.email +
 * auth.password_hash). That pair is still read as a fallback, but accounts now
 * live in the `users` table so there can be more than one, each with a role:
 *
 *   superadmin — everything, including the /access-log view
 *   user       — everything except the access log
 *
 * Roles are deliberately coarse: this app has one privileged screen, so a full
 * permission table would be more machinery than the problem needs. Add roles
 * here (and to canViewAccessLog) if that stops being true.
 */
final class Users
{
    public const SUPERADMIN = 'superadmin';
    public const USER       = 'user';

    /** Roles that may open the access log. */
    public static function canViewAccessLog(?string $role): bool
    {
        return $role === self::SUPERADMIN;
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /** Anything not a known role is treated as the least-privileged one. */
    public static function normalizeRole(string $role): string
    {
        $r = strtolower(trim($role));
        return $r === self::SUPERADMIN ? self::SUPERADMIN : self::USER;
    }

    public static function count(): int
    {
        return (int)Db::scalar("SELECT COUNT(*) FROM users");
    }

    /** @return array<int,array> id, email, role, display_name, created_at */
    public static function all(): array
    {
        return Db::all("SELECT id, email, role, display_name, created_at FROM users ORDER BY role, email");
    }

    public static function find(string $email): ?array
    {
        return Db::one("SELECT * FROM users WHERE email = ?", [self::normalizeEmail($email)]);
    }

    /**
     * Create the account, or update the password/role/name of an existing one.
     * The plaintext password is hashed here and never stored.
     */
    public static function set(string $email, string $password, string $role = self::USER, string $displayName = ''): void
    {
        $email = self::normalizeEmail($email);
        $role  = self::normalizeRole($role);
        $hash  = password_hash($password, PASSWORD_DEFAULT);

        if (self::find($email)) {
            Db::q("UPDATE users SET password_hash = ?, role = ?, display_name = ? WHERE email = ?",
                [$hash, $role, $displayName, $email]);
            return;
        }
        Db::insert('users', [
            'email' => $email, 'password_hash' => $hash, 'role' => $role, 'display_name' => $displayName,
        ]);
    }

    /** Change a role without touching the password. False if no such account. */
    public static function setRole(string $email, string $role): bool
    {
        if (!self::find($email)) return false;
        Db::q("UPDATE users SET role = ? WHERE email = ?", [self::normalizeRole($role), self::normalizeEmail($email)]);
        return true;
    }

    /**
     * Remove an account. Refuses to delete the last superadmin — losing it
     * would make the access log unreachable for everyone, with no way back
     * except the CLI.
     */
    public static function delete(string $email): bool
    {
        $u = self::find($email);
        if (!$u) return false;
        if ($u['role'] === self::SUPERADMIN && self::superadminCount() <= 1) return false;
        Db::q("DELETE FROM users WHERE email = ?", [self::normalizeEmail($email)]);
        return true;
    }

    public static function superadminCount(): int
    {
        return (int)Db::scalar("SELECT COUNT(*) FROM users WHERE role = ?", [self::SUPERADMIN]);
    }

    /**
     * Verify a sign-in. Returns the user row on success, null on failure.
     * Unknown emails still run a hash comparison so a missing account and a
     * wrong password take the same time to reject.
     */
    public static function check(string $email, string $password): ?array
    {
        $u = self::find($email);
        if (!$u) {
            password_verify($password, '$2y$10$usesomesillystringforsalt0123456789abcdefghijklmnopqrs');
            return null;
        }
        return password_verify($password, (string)$u['password_hash']) ? $u : null;
    }

    /**
     * The account the one-click quick-login button signs in as: whatever
     * auth.quick_login_email names, else the legacy single admin, else the
     * first non-privileged account.
     */
    public static function quickLoginUser(): ?array
    {
        foreach ([(string)Settings::get('auth.quick_login_email', ''), (string)Settings::get('auth.email', '')] as $candidate) {
            if ($candidate === '') continue;
            $u = self::find($candidate);
            if ($u) return $u;
        }
        return Db::one("SELECT * FROM users WHERE role != ? ORDER BY id LIMIT 1", [self::SUPERADMIN]);
    }

    /**
     * Quick login is a public password bypass — anyone who loads /login can
     * click it. A superadmin behind that button would hand the access log to
     * the internet, so it is never allowed to sign one in.
     */
    public static function canQuickLogin(?array $user): bool
    {
        return $user !== null && $user['role'] !== self::SUPERADMIN;
    }

    /** Short label for the sidebar avatar / button, e.g. "Simon". */
    public static function label(?array $user): string
    {
        if (!$user) return 'Admin';
        $n = trim((string)($user['display_name'] ?? ''));
        if ($n !== '') return $n;
        $local = explode('@', (string)$user['email'])[0] ?? '';
        return $local === '' ? 'Admin' : ucfirst((string)preg_replace('/[._-].*$/', '', $local));
    }

    /**
     * One-time move of the legacy single admin (app_settings) into `users`.
     * It was the only account, so it keeps full access; re-runs are no-ops
     * once a row with that email exists.
     */
    public static function seedFromLegacy(): ?string
    {
        $email = self::normalizeEmail((string)Settings::get('auth.email', ''));
        $hash  = (string)Settings::get('auth.password_hash', '');
        if ($email === '' || $hash === '' || self::find($email)) return null;

        Db::insert('users', [
            'email'         => $email,
            'password_hash' => $hash,
            'role'          => self::SUPERADMIN,
            'display_name'  => (string)Settings::get('auth.display_name', ''),
        ]);
        return $email;
    }
}
