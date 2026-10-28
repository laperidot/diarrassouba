<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Session;
use App\Services\UserService;

final class AdminController extends Controller
{
    public function users(): string
    {
        Auth::require('users.manage');
        return $this->view('users/index', ['title' => 'Utilisateurs', 'users' => (new UserService())->all()]);
    }

    public function userForm(?string $id = null): string
    {
        Auth::require('users.manage');
        $u = null;
        if ($id !== null) {
            $u = (new UserService())->find((int) $id);
            $this->notFoundUnless($u);
        }
        return $this->view('users/form', ['title' => $u ? 'Modifier l’utilisateur' : 'Nouvel utilisateur', 'u' => $u]);
    }

    public function userStore(): never
    {
        Auth::require('users.manage');
        $this->attempt(fn () => (new UserService())->save(null, array_merge(Request::all(), ['password' => (string) ($_POST['password'] ?? '')])), 'utilisateurs/nouveau');
        Session::flash('success', 'Utilisateur créé. Il devra choisir son propre mot de passe à la première connexion.');
        $this->redirect('utilisateurs');
    }

    public function userUpdate(string $id): never
    {
        Auth::require('users.manage');
        $this->attempt(fn () => (new UserService())->save((int) $id, array_merge(Request::all(), ['password' => (string) ($_POST['password'] ?? '')])), "utilisateurs/$id");
        Session::flash('success', 'Utilisateur mis à jour.');
        $this->redirect('utilisateurs');
    }

    public function tariffs(): string
    {
        Auth::require('tariffs.manage');
        return $this->view('users/tariffs', ['title' => 'Tarifs au kilogramme', 'tariffs' => (new UserService())->tariffs()]);
    }

    public function tariffStore(): never
    {
        Auth::require('tariffs.manage');
        $this->attempt(fn () => (new UserService())->saveTariff(null, Request::all()), 'tarifs');
        Session::flash('success', 'Tarif ajouté.');
        $this->redirect('tarifs');
    }

    public function tariffUpdate(string $id): never
    {
        Auth::require('tariffs.manage');
        $this->attempt(fn () => (new UserService())->saveTariff((int) $id, Request::all()), 'tarifs');
        Session::flash('success', 'Tarif mis à jour. Les colis déjà enregistrés conservent leur tarif.');
        $this->redirect('tarifs');
    }

    /** Coordonnées affichées aux clients (suivi public, documents) : modifiables sans toucher au serveur. */
    public function settings(): string
    {
        Auth::require('settings.manage');
        $values = [];
        foreach (\App\Core\Settings::EDITABLE as $key => $_) {
            $values[$key] = \App\Core\Settings::get($key);
        }
        return $this->view('users/settings', ['title' => 'Paramètres', 'values' => $values, 'saved' => \App\Core\Settings::saved()]);
    }

    public function settingsSave(): never
    {
        Auth::require('settings.manage');
        $changed = $this->attempt(fn () => \App\Core\Settings::save($_POST, Auth::id()), 'parametres');
        Session::flash('success', $changed ? 'Paramètres enregistrés : la page de suivi et les nouveaux documents les utilisent immédiatement.' : 'Aucune modification.');
        $this->redirect('parametres');
    }

    public function audit(): string
    {
        Auth::require('audit.view');
        $f = ['entity_type' => Request::query('type'), 'entity_id' => Request::query('id'), 'action' => Request::query('action')];
        return $this->view('users/audit', ['title' => 'Journal des opérations', 'logs' => (new UserService())->audit($f, 200), 'f' => $f]);
    }
}
