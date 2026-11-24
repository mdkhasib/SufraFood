<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit;
use App\Support\CrudConfig;
use App\Support\Features;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class CrudController extends Controller
{
    protected function cfg(Request $r, string $res): array
    {
        $cfg = CrudConfig::get($res);
        abort_unless($cfg, 404);
        abort_unless(CrudConfig::allowed($cfg, $r->user()), 403, 'You cannot manage '.$cfg['title'].'.');
        $cfg['res'] = $res;

        return $cfg;
    }

    /** Rows this page manages (e.g. sale items only). */
    protected function query(array $cfg)
    {
        $q = $cfg['model']::query();

        return isset($cfg['scope']) ? ($cfg['scope'])($q) : $q;
    }

    public function index(Request $r, string $res)
    {
        $cfg = $this->cfg($r, $res);
        $q = $this->query($cfg)->with($cfg['with'] ?? []);
        if ($res === 'users') {
            $q->where('is_super_admin', false);
        }
        if ($r->filled('q') && ! empty($cfg['search'])) {
            $q->where(fn ($w) => collect($cfg['search'])->each(fn ($c) => $w->orWhere($c, 'like', '%'.$r->q.'%')));
        }
        foreach ($cfg['filter'] ?? [] as $col => $f) {
            if ($r->filled($col)) {
                $q->where($col, $r->input($col));
            }
        }
        $rows = $q->orderBy($cfg['order'] ?? 'id')->paginate(50)->withQueryString();

        return view('crud.index', compact('cfg', 'rows'));
    }

    public function create(Request $r, string $res)
    {
        $cfg = $this->cfg($r, $res);

        return view('crud.form', ['cfg' => $cfg, 'row' => new $cfg['model'], 'fields' => ($cfg['fields'])()]);
    }

    public function edit(Request $r, string $res, int $id)
    {
        $cfg = $this->cfg($r, $res);

        return view('crud.form', ['cfg' => $cfg, 'row' => $this->query($cfg)->findOrFail($id), 'fields' => ($cfg['fields'])()]);
    }

    protected function data(Request $r, array $cfg, $row = null): array
    {
        $fields = ($cfg['fields'])();
        $rules = [];
        foreach ($fields as $k => $f) {
            if (($f[2] ?? null) === 'required') {
                $rules[$k] = 'required';
            }
        }
        foreach ($cfg['rules'] ?? [] as $k => $rule) {
            $rules[$k] = trim(($rules[$k] ?? '').'|'.str_replace('{id}', (string) ($row?->id ?? 'NULL'), $rule), '|');
        }
        foreach ($fields as $k => $f) {
            if ($f[1] === 'image') {
                $rules[$k] = 'nullable|image|mimes:png,jpg,jpeg,webp|max:3072';
            }
        }
        $r->validate($rules, [], collect($fields)->map(fn ($f) => $f[0])->all());
        $data = [];
        foreach ($fields as $k => $f) {
            $v = $r->input($k);
            if ($f[1] === 'image') {
                if ($r->hasFile($k)) {
                    $v = $r->file($k)->store($cfg['res'] === 'users' ? 'people' : 'products', 'public');
                } elseif ($r->boolean($k.'__remove')) {
                    $v = null;
                } else {
                    continue;
                }
            } elseif ($f[1] === 'bool') {
                $v = $r->boolean($k);
            } elseif ($f[1] === 'password') {
                if (! $v) {
                    continue;
                }
                $v = Hash::make($v);
            } elseif (($v === '' || $v === null) && array_key_exists($k, $cfg['defaults'] ?? [])) {
                $v = $cfg['defaults'][$k]; // e.g. "Cooked at" left empty → NONE, units per buying unit → 1
            } elseif ($v === '' || $v === null) {
                $v = in_array($f[1], ['number']) && ! str_contains($f[0], 'limit') && ! in_array($k, ['lat', 'lng']) ? 0 : null;
            }
            $data[$k] = $v;
        }
        $data = array_merge($data, $cfg['fixed'] ?? []);
        if ($cfg['res'] === 'users') {
            if (! $row && empty($data['password'])) {
                $data['password'] = Hash::make('123456');
            }
            if ($r->user()->is_super_admin) {
                $data['is_company_admin'] = $r->boolean('is_company_admin');
            }
            $data['employee_code'] = strtoupper($data['employee_code']);
        }

        return $data;
    }

    public function store(Request $r, string $res)
    {
        $cfg = $this->cfg($r, $res);
        $row = $cfg['model']::create($this->data($r, $cfg));
        Audit::log('master.created', $row, null, $row->toArray());
        Features::flush();

        return redirect()->route('crud.index', $res)->with('ok', 'Saved');
    }

    public function update(Request $r, string $res, int $id)
    {
        $cfg = $this->cfg($r, $res);
        $row = $this->query($cfg)->findOrFail($id);
        $before = $row->toArray();
        $row->update($this->data($r, $cfg, $row));
        Audit::log('master.updated', $row, $before, $row->fresh()->toArray());

        return redirect()->route('crud.index', $res)->with('ok', 'Updated');
    }

    public function destroy(Request $r, string $res, int $id)
    {
        $cfg = $this->cfg($r, $res);
        $row = $this->query($cfg)->findOrFail($id);
        try {
            $row->delete();
        } catch (\Throwable $e) {
            return back()->with('error', 'Cannot delete — it is used by other records. Mark it inactive instead.');
        }
        Audit::log('master.deleted', $row, $row->toArray());

        return back()->with('ok', 'Deleted');
    }

    public function resetDevice(Request $r, int $id)
    {
        $this->cfg($r, 'users');
        $u = User::findOrFail($id);
        $u->update(['device_id' => null]);
        Audit::log('user.device_reset', $u);

        return back()->with('ok', 'Device reset for '.$u->name);
    }
}
