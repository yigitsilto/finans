<details class="inline-details">
    <summary class="btn btn-secondary btn-sm">Düzelt</summary>
    <form method="POST" action="{{ route('loans.installments.update', $inst) }}" class="stack-form">
        @csrf @method('PATCH')
        <div class="modal-title">{{ $inst->number }}. taksiti düzelt</div>
        <small class="muted">Bankanın ödeme planındaki değerleri girin.</small>
        <label>Vade <input type="date" name="due_date" class="input input-sm" value="{{ $inst->due_date->toDateString() }}" required></label>
        <label>Anapara <input name="principal" class="input input-sm num-input" value="{{ \App\Support\Money::format($inst->principal, false) }}" required></label>
        <label>Faiz <input name="interest" class="input input-sm num-input" value="{{ \App\Support\Money::format($inst->interest, false) }}" required></label>
        <button class="btn btn-secondary btn-sm">Kaydet</button>
    </form>
</details>
