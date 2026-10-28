<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Référentiel métier : statuts, transitions autorisées, rôles, permissions, catégories.
 * Toute règle de transition passe par ce fichier pour rester cohérente.
 */
final class Domain
{
    // ---------------- Statuts des colis ----------------
    /** Parcours normal, dans l'ordre. */
    public const PARCEL_FLOW = [
        'registered'        => 'Colis enregistré',
        'awaiting'          => "En attente d'expédition",
        'shipped'           => 'Expédié',
        'in_transit'        => 'En transit',
        'arrived'           => 'Arrivé à destination',
        'at_depot'          => 'Disponible au dépôt',
        'out_for_delivery'  => 'En cours de livraison',
        'delivered'         => 'Livré ou récupéré',
    ];

    public const PARCEL_EXCEPTIONS = [
        'anomaly'          => 'Anomalie',
        'blocked'          => 'Colis bloqué',
        'delivery_failed'  => 'Livraison échouée',
        'check_required'   => 'Vérification requise',
    ];

    /** Libellés courts pour le rail de progression. */
    public const PARCEL_SHORT = [
        'registered' => 'Enregistré', 'awaiting' => 'En attente', 'shipped' => 'Expédié',
        'in_transit' => 'En vol', 'arrived' => 'Arrivé', 'at_depot' => 'Au dépôt',
        'out_for_delivery' => 'En livraison', 'delivered' => 'Remis',
    ];

    /** Famille de couleur par statut (voir tokens CSS --st-*). */
    public const PARCEL_TONE = [
        'registered' => 'ground', 'awaiting' => 'ground',
        'shipped' => 'air', 'in_transit' => 'air',
        'arrived' => 'wait', 'at_depot' => 'wait',
        'out_for_delivery' => 'go', 'delivered' => 'done',
        'anomaly' => 'alert', 'blocked' => 'alert', 'delivery_failed' => 'alert', 'check_required' => 'alert',
    ];

    /** Statuts qui ne peuvent être posés que par un module dédié (et pas par changement manuel). */
    public const PARCEL_STATUS_RESERVED = ['delivered'];

    public static function parcelLabel(string $status): string
    {
        return self::PARCEL_FLOW[$status] ?? self::PARCEL_EXCEPTIONS[$status] ?? $status;
    }

    public static function parcelStatuses(): array
    {
        return self::PARCEL_FLOW + self::PARCEL_EXCEPTIONS;
    }

    /**
     * Transitions autorisées pour un colis.
     * - avance d'une étape (ou saut contrôlé registered -> shipped lors d'un départ groupé),
     * - recul d'une étape pour corriger une erreur de saisie,
     * - passage en exception depuis n'importe quel statut non final,
     * - sortie d'exception vers n'importe quel statut du parcours sauf « delivered ».
     */
    public static function parcelTransitions(string $from): array
    {
        $flow = array_keys(self::PARCEL_FLOW);
        $exceptions = array_keys(self::PARCEL_EXCEPTIONS);
        if ($from === 'delivered') {
            return ['check_required'];
        }
        if (in_array($from, $exceptions, true)) {
            $targets = array_diff($flow, ['delivered']);
            return array_values(array_merge($targets, array_diff($exceptions, [$from])));
        }
        $i = array_search($from, $flow, true);
        if ($i === false) {
            return [];
        }
        $targets = [];
        if (isset($flow[$i + 1])) {
            $targets[] = $flow[$i + 1];
        }
        if ($from === 'registered') {
            $targets[] = 'shipped';
        }
        if ($from === 'arrived') {
            $targets[] = 'out_for_delivery'; // livraison directe depuis l'aéroport
        }
        if ($from === 'at_depot') {
            $targets[] = 'delivered'; // retrait au dépôt (réservé au module Livraisons)
        }
        if ($i > 0) {
            $targets[] = $flow[$i - 1];
        }
        $targets = array_merge($targets, $from === 'out_for_delivery' ? ['delivery_failed', 'anomaly', 'blocked', 'check_required'] : ['anomaly', 'blocked', 'check_required']);
        return array_values(array_unique($targets));
    }

    public static function canParcelTransition(string $from, string $to): bool
    {
        return in_array($to, self::parcelTransitions($from), true);
    }

    // ---------------- Statuts des dossiers d'expédition ----------------
    public const SHIPMENT_STATUSES = [
        'preparation' => 'En préparation',
        'departed'    => 'Parti',
        'in_transit'  => 'En transit',
        'arrived'     => 'Arrivé',
        'closed'      => 'Clôturé',
        'cancelled'   => 'Annulé',
    ];

    public const SHIPMENT_TRANSITIONS = [
        'preparation' => ['departed', 'cancelled'],
        'departed'    => ['in_transit', 'arrived', 'preparation'],
        'in_transit'  => ['arrived'],
        'arrived'     => ['closed', 'in_transit'],
        'closed'      => [],
        'cancelled'   => ['preparation'],
    ];

    /** Statut colis proposé quand le dossier change (application optionnelle et filtrée). */
    public const SHIPMENT_TO_PARCEL = [
        'departed'   => 'shipped',
        'in_transit' => 'in_transit',
        'arrived'    => 'arrived',
    ];

    /** Libellé d'action pour chaque changement de statut d'un dossier (boutons). */
    public const SHIPMENT_ACTIONS = [
        'preparation' => 'Repasser en préparation',
        'departed'    => 'Confirmer le départ',
        'in_transit'  => 'Marquer en transit',
        'arrived'     => 'Confirmer l’arrivée',
        'closed'      => 'Clôturer le dossier',
        'cancelled'   => 'Annuler le dossier',
    ];

    /** Étape « naturelle » suivante, proposée par défaut. */
    public const SHIPMENT_NEXT = [
        'preparation' => 'departed',
        'departed'    => 'arrived',
        'in_transit'  => 'arrived',
        'arrived'     => 'closed',
        'cancelled'   => 'preparation',
    ];

    public const SHIPMENT_TONE = [
        'preparation' => 'ground', 'departed' => 'air', 'in_transit' => 'air',
        'arrived' => 'wait', 'closed' => 'done', 'cancelled' => 'muted',
    ];

    /** Statuts de dossier « actifs » : un colis ne peut appartenir qu'à un seul dossier actif. */
    public const SHIPMENT_ACTIVE = ['preparation', 'departed', 'in_transit', 'arrived'];

    // ---------------- Factures ----------------
    public const INVOICE_STATUSES = [
        'draft'     => 'Brouillon',
        'validated' => 'Validée',
        'partial'   => 'Partiellement payée',
        'paid'      => 'Payée',
        'cancelled' => 'Annulée',
        'credited'  => 'Avoir émis',
    ];

    public const INVOICE_TONE = [
        'draft' => 'ground', 'validated' => 'air', 'partial' => 'wait',
        'paid' => 'done', 'cancelled' => 'muted', 'credited' => 'muted',
    ];

    public const PAYMENT_TERMS = [
        'before_shipping' => 'Paiement intégral avant expédition',
        'on_arrival'      => "Paiement à l'arrivée",
        'deposit'         => 'Acompte puis solde',
        'installments'    => 'Paiement en plusieurs échéances',
        'on_delivery'     => 'Paiement à la livraison',
    ];

    public const PAYMENT_METHODS = [
        'cash'          => 'Espèces',
        'orange_money'  => 'Orange Money',
        'mtn_momo'      => 'MTN Mobile Money',
        'moov_money'    => 'Moov Money',
        'wave'          => 'Wave',
        'bank_transfer' => 'Virement bancaire',
        'card'          => 'Carte bancaire',
        'other'         => 'Autre',
    ];

    // ---------------- Dépenses ----------------
    public const EXPENSE_CATEGORIES = [
        'air_ticket' => "Billets d'avion",
        'air_freight' => 'Fret aérien',
        'handling'   => 'Manutention',
        'customs'    => 'Douane et formalités',
        'local_transport' => 'Transport local',
        'delivery'   => 'Livraison',
        'storage'    => 'Stockage',
        'packaging'  => 'Emballage',
        'other'      => 'Autres dépenses opérationnelles',
    ];

    public const EXPENSE_PAYMENT_STATUS = [
        'unpaid' => 'À payer',
        'partial' => 'Partiellement payée',
        'paid' => 'Payée',
    ];

    // ---------------- Livraisons ----------------
    public const DELIVERY_MODES = [
        'pickup'   => 'Retrait au dépôt',
        'delivery' => 'Livraison à domicile',
    ];

    public const DELIVERY_STATUSES = [
        'planned'     => 'Planifiée',
        'in_progress' => 'En cours',
        'done'        => 'Remise confirmée',
        'failed'      => 'Tentative échouée',
        'cancelled'   => 'Annulée',
    ];

    public const DELIVERY_TONE = [
        'planned' => 'ground', 'in_progress' => 'go', 'done' => 'done', 'failed' => 'alert', 'cancelled' => 'muted',
    ];

    // ---------------- Devises (exposant = nombre de décimales) ----------------
    public const CURRENCIES = [
        'XOF' => ['label' => 'Franc CFA (XOF)', 'symbol' => 'F CFA', 'exp' => 0],
        'EUR' => ['label' => 'Euro (EUR)', 'symbol' => '€', 'exp' => 2],
        'USD' => ['label' => 'Dollar US (USD)', 'symbol' => '$', 'exp' => 2],
        'EGP' => ['label' => 'Livre égyptienne (EGP)', 'symbol' => 'EGP', 'exp' => 2],
    ];

    public const COUNTRIES = [
        'EG' => 'Égypte', 'CI' => "Côte d'Ivoire", 'BF' => 'Burkina Faso', 'ML' => 'Mali',
        'SN' => 'Sénégal', 'GN' => 'Guinée', 'TG' => 'Togo', 'BJ' => 'Bénin', 'NE' => 'Niger',
        'GH' => 'Ghana', 'LR' => 'Liberia', 'NG' => 'Nigeria', 'FR' => 'France', 'AE' => 'Émirats arabes unis',
        'TR' => 'Turquie', 'CN' => 'Chine',
    ];

    public const COUNTRY_CITY = [
        'EG' => 'Le Caire', 'CI' => 'Abidjan', 'BF' => 'Ouagadougou', 'ML' => 'Bamako', 'SN' => 'Dakar',
        'GN' => 'Conakry', 'TG' => 'Lomé', 'BJ' => 'Cotonou', 'NE' => 'Niamey', 'GH' => 'Accra',
    ];

    /** Codes aéroport affichés sur le rail (purement informatif). */
    public const CITY_IATA = [
        'le caire' => 'CAI', 'cairo' => 'CAI', 'abidjan' => 'ABJ', 'ouagadougou' => 'OUA', 'bamako' => 'BKO',
        'dakar' => 'DSS', 'conakry' => 'CKY', 'lomé' => 'LFW', 'lome' => 'LFW', 'cotonou' => 'COO',
        'niamey' => 'NIM', 'accra' => 'ACC', 'alexandrie' => 'HBE', 'alexandria' => 'HBE', 'lagos' => 'LOS',
    ];

    public static function iata(?string $city, ?string $country = null): string
    {
        $key = mb_strtolower(trim((string) $city));
        if (isset(self::CITY_IATA[$key])) {
            return self::CITY_IATA[$key];
        }
        $clean = strtoupper(preg_replace('/[^A-Za-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', (string) $city) ?: ''));
        return $clean !== '' ? substr($clean, 0, 3) : (string) $country;
    }

    // ---------------- Rôles et permissions ----------------
    public const ROLES = [
        'admin'     => 'Administrateur',
        'manager'   => 'Responsable',
        'registrar' => "Agent d'enregistrement",
        'logistics' => 'Agent logistique',
        'cashier'   => 'Comptable / caissier',
        'courier'   => 'Agent de livraison',
    ];

    /** Permission => rôles autorisés (l'administrateur a tout). */
    public const PERMISSIONS = [
        'dashboard.view'      => ['manager', 'registrar', 'logistics', 'cashier', 'courier'],
        'dashboard.finance'   => ['manager', 'cashier'],
        'clients.view'        => ['manager', 'registrar', 'logistics', 'cashier'],
        'clients.edit'        => ['manager', 'registrar'],
        'clients.contacts'    => ['manager', 'registrar', 'logistics', 'cashier'],
        'parcels.view'        => ['manager', 'registrar', 'logistics', 'cashier', 'courier'],
        'parcels.create'      => ['manager', 'registrar'],
        'parcels.edit'        => ['manager', 'registrar'],
        'parcels.status'      => ['manager', 'registrar', 'logistics'],
        'parcels.finance'     => ['manager', 'registrar', 'cashier'],
        'parcels.internal'    => ['manager', 'registrar', 'logistics', 'cashier'],
        'shipments.view'      => ['manager', 'registrar', 'logistics', 'cashier'],
        'shipments.edit'      => ['manager', 'logistics'],
        // Mettre un colis dans un vol EN PRÉPARATION (comptoir compris) ; changer le statut d'un vol reste « shipments.edit ».
        'shipments.load'      => ['manager', 'registrar', 'logistics'],
        'invoices.view'       => ['manager', 'cashier'],
        'invoices.edit'       => ['manager', 'cashier'],
        'invoices.cancel'     => ['manager'],
        'payments.record'     => ['manager', 'cashier'],
        'payments.reverse'    => ['manager'],
        'expenses.view'       => ['manager', 'cashier', 'logistics'],
        'expenses.edit'       => ['manager', 'cashier', 'logistics'],
        'expenses.cancel'     => ['manager'],
        'deliveries.view'     => ['manager', 'registrar', 'logistics', 'courier'],
        'deliveries.manage'   => ['manager', 'logistics'],
        'deliveries.confirm'  => ['manager', 'logistics', 'courier'],
        'tariffs.manage'      => ['manager'],
        'users.manage'        => [],
        'settings.manage'     => [],
        'audit.view'          => ['manager'],
    ];

    public static function roleCan(string $role, string $permission): bool
    {
        if ($role === 'admin') {
            return true;
        }
        return in_array($role, self::PERMISSIONS[$permission] ?? [], true);
    }
}
