<?php

namespace App\Http\Controllers;

use App\Support\GoogleSignIn;
use App\Support\RoomsArea;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The privacy notice (docs/architecture.md §7): what is stored, why, for how
 * long, how to delete it, what the board says aloud (a first name only from
 * the built-in name list, sent nowhere; ADR 016), who operates this
 * instance, a section for parents who play with their own children at home
 * (§7 "Parents and their children", ADR 013), the note a teacher copies
 * for a class's parents and — only while the rooms switch is on — the
 * section «Otaqlar» for adults who play in rooms (§12 "Adults' privacy",
 * ADR 024 decision 10). With the switch off the page gets nothing about
 * rooms (ADR 019 decision 4). Only while the instance's live voice is on
 * does that section get `voice: true`, so the page shows the part «Canlı
 * səs» and the voice forms of three sentences (ADR 031 decisions 16–17).
 * Public, in every interface language.
 */
final class PrivacyController extends Controller
{
    public function __invoke(): Response
    {
        $name = Config::get('elifba.operator.name');
        $email = Config::get('elifba.operator.email');

        return Inertia::render('privacy', [
            'retentionDays' => Config::integer('elifba.retention_days'),
            // At most this many children per parent (ADR 013).
            'childrenMax' => Config::integer('elifba.children.max'),
            // What Google sign-in shares, when this instance offers it (ADR 008).
            'googleSignIn' => GoogleSignIn::enabled(),
            'operator' => [
                'name' => is_string($name) && trim($name) !== '' ? trim($name) : null,
                'email' => is_string($email) && trim($email) !== '' ? trim($email) : null,
            ],
            // How long an idle room keeps its code (§12 "Rooms and joining"); `voice` while the live voice is on (C3).
            ...(RoomsArea::enabled() ? ['rooms' => [
                'idleHours' => Config::integer('elifba.rooms.idle_hours'),
                ...(RoomsArea::voice() ? ['voice' => true] : []),
            ]] : []),
        ]);
    }
}
