<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;
use App\Services\UserService;

final class AuthController extends Controller
{
    public function home(): never
    {
        $this->redirect(Auth::user() ? 'tableau-de-bord' : 'connexion');
    }

    public function loginForm(): string
    {
        if (Auth::user()) {
            $this->redirect('tableau-de-bord');
        }
        $needsInstall = (int) Db::get()->value('SELECT COUNT(*) FROM users') === 0;
        return $this->view('auth/login', ['needsInstall' => $needsInstall, 'title' => 'Connexion'], 'auth');
    }

    public function login(): never
    {
        $email = Request::str('email');
        $result = Auth::attempt($email, (string) ($_POST['password'] ?? ''), Request::ip());
        if (!$result['ok']) {
            Session::setOld(['email' => $email]);
            Session::flash('error', $result['locked']
                ? 'Trop de tentatives. Pour votre sécurité, la connexion est suspendue 15 minutes.'
                : 'E-mail ou mot de passe incorrect.');
            $this->redirect('connexion');
        }
        Auth::login($result['user']);
        $intended = $_SESSION['intended'] ?? '/tableau-de-bord';
        unset($_SESSION['intended']);
        $this->redirect(str_starts_with($intended, '/') && !str_starts_with($intended, '//') ? $intended : '/tableau-de-bord');
    }

    public function logout(): never
    {
        Auth::logout();
        Session::start();
        Session::flash('success', 'Vous êtes déconnecté.');
        $this->redirect('connexion');
    }

    public function passwordForm(): string
    {
        Auth::require('dashboard.view');
        return $this->view('auth/password', ['title' => 'Mot de passe']);
    }

    public function changePassword(): never
    {
        Auth::require('dashboard.view');
        $this->attempt(fn () => (new UserService())->changeOwnPassword(
            (int) Auth::id(), (string) ($_POST['current_password'] ?? ''), (string) ($_POST['password'] ?? ''), (string) ($_POST['password_confirm'] ?? '')
        ), 'mot-de-passe');
        Session::flash('success', 'Mot de passe modifié. Vos autres sessions ouvertes ont été fermées.');
        $this->redirect('tableau-de-bord');
    }

    /**
     * Création du premier administrateur depuis le navigateur (hébergement sans SSH).
     * Disponible uniquement si aucun utilisateur n'existe ET si INSTALL_TOKEN est défini.
     */
    public function installForm(): string
    {
        $this->guardInstall();
        return $this->view('auth/install', ['title' => 'Installation'], 'auth');
    }

    public function install(): never
    {
        $this->guardInstall();
        if (!hash_equals((string) Config::get('INSTALL_TOKEN'), Request::str('install_token'))) {
            Session::flash('error', 'Jeton d’installation incorrect.');
            $this->redirect('installation');
        }
        $id = $this->attempt(fn () => (new UserService())->save(null, [
            'name' => Request::str('name'), 'email' => Request::str('email'), 'password' => (string) ($_POST['password'] ?? ''),
            'role_code' => 'admin', 'is_active' => 1,
        ]), 'installation');
        Db::get()->update('users', ['must_change_password' => 0], 'id = ?', [$id]);
        Audit::log('install.admin_created', 'user', $id, [], $id);
        Session::flash('success', 'Administrateur créé. Connectez-vous, puis retirez INSTALL_TOKEN du fichier .env.');
        $this->redirect('connexion');
    }

    private function guardInstall(): void
    {
        $token = (string) Config::get('INSTALL_TOKEN', '');
        if (strlen($token) < 16 || (int) Db::get()->value('SELECT COUNT(*) FROM users') > 0) {
            throw new HttpException(404);
        }
    }
}
