<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeatureSuggestion extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'product', 'title', 'details', 'status', 'admin_note', 'source',
    ];

    public const STATUSES = ['pending', 'planned', 'completed', 'rejected'];

    /**
     * Task 1202: PRA provisional-billing elaan popup — raay rows land in this
     * table with source='pra_elaan' (Madadgar escalation pattern). The titles
     * below are the ONLY titles that write path uses, so the admin tally can
     * count choices by exact title match. Do not edit the strings once live —
     * existing rows keep the old wording and would fall out of the tally.
     */
    public const PRA_ELAAN_SOURCE = 'pra_elaan';

    /**
     * Reserved title prefix (Task 1229): on a drifted schema without the
     * source column, elaan rows are recognised by this prefix. The normal
     * suggestion endpoint REJECTS titles starting with it, so the prefix is
     * a safe discriminator, not user-controllable text.
     */
    public const PRA_ELAAN_TITLE_PREFIX = 'PRA elaan:';

    public const PRA_ELAAN_CHOICES = [
        'band' => 'PRA elaan: Haan, provisional billing band kar dein',
        'jari' => 'PRA elaan: Nahi, provisional billing chalti rehne dein',
        'aur'  => 'PRA elaan: Kuch aur tajweez hai',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
