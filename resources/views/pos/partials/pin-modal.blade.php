<div id="pinModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm" x-data="pinVerification()" x-cloak>
    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 p-8 w-full max-w-sm mx-4">
        <div class="text-center mb-6">
            <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
                <svg class="w-8 h-8 text-purple-600 dark:text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            </div>
            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Confidential PIN Required</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Enter your company's confidential PIN to access local data</p>
        </div>

        <div id="pinError" class="hidden mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm text-center"></div>

        <div class="flex justify-center gap-3 mb-6">
            <input type="password" maxlength="1" class="pin-digit w-12 h-14 text-center text-2xl font-bold rounded-xl border-2 border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition" data-index="0">
            <input type="password" maxlength="1" class="pin-digit w-12 h-14 text-center text-2xl font-bold rounded-xl border-2 border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition" data-index="1">
            <input type="password" maxlength="1" class="pin-digit w-12 h-14 text-center text-2xl font-bold rounded-xl border-2 border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition" data-index="2">
            <input type="password" maxlength="1" class="pin-digit w-12 h-14 text-center text-2xl font-bold rounded-xl border-2 border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition" data-index="3">
            <input type="password" maxlength="1" class="pin-digit w-12 h-14 text-center text-2xl font-bold rounded-xl border-2 border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition hidden" data-index="4">
            <input type="password" maxlength="1" class="pin-digit w-12 h-14 text-center text-2xl font-bold rounded-xl border-2 border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition hidden" data-index="5">
        </div>

        <div class="flex gap-3">
            <button onclick="closePinModal()" class="flex-1 px-4 py-2.5 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-xl text-sm font-semibold hover:bg-gray-50 dark:hover:bg-gray-800 transition">Cancel</button>
            <button onclick="submitPin()" id="pinSubmitBtn" class="flex-1 px-4 py-2.5 bg-purple-600 text-white rounded-xl text-sm font-semibold hover:bg-purple-700 transition disabled:opacity-50" disabled>Verify</button>
        </div>
    </div>
</div>

<script>
let pinCallback = null;

function openPinModal(callback) {
    pinCallback = callback;
    const modal = document.getElementById('pinModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.getElementById('pinError').classList.add('hidden');
    document.querySelectorAll('.pin-digit').forEach(d => { d.value = ''; });
    document.querySelector('.pin-digit[data-index="0"]').focus();
}

function closePinModal() {
    const modal = document.getElementById('pinModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    pinCallback = null;
}

document.querySelectorAll('.pin-digit').forEach(input => {
    input.addEventListener('input', function () {
        if (this.value.length === 1) {
            const next = this.nextElementSibling;
            if (next && next.classList.contains('pin-digit') && !next.classList.contains('hidden')) {
                next.focus();
            }
        }
        updatePinSubmitBtn();
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Backspace' && this.value === '') {
            const prev = this.previousElementSibling;
            if (prev && prev.classList.contains('pin-digit')) {
                prev.focus();
                prev.value = '';
            }
        }
        if (e.key === 'Enter') {
            submitPin();
        }
    });
});

function updatePinSubmitBtn() {
    const digits = document.querySelectorAll('.pin-digit:not(.hidden)');
    const pin = Array.from(digits).map(d => d.value).join('');
    document.getElementById('pinSubmitBtn').disabled = pin.length < 4;
}

function submitPin() {
    const digits = document.querySelectorAll('.pin-digit:not(.hidden)');
    const pin = Array.from(digits).map(d => d.value).join('');
    if (pin.length < 4) return;

    document.getElementById('pinSubmitBtn').disabled = true;
    document.getElementById('pinSubmitBtn').textContent = 'Verifying...';

    fetch('{{ route("pos.api.verify-pin") }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        body: JSON.stringify({ pin: pin })
    })
    .then(r => r.json().then(data => ({ status: r.status, data })))
    .then(({ status, data }) => {
        if (data.success) {
            closePinModal();
            if (pinCallback) pinCallback();
        } else {
            document.getElementById('pinError').textContent = data.message;
            document.getElementById('pinError').classList.remove('hidden');
            document.querySelectorAll('.pin-digit').forEach(d => { d.value = ''; });
            document.querySelector('.pin-digit[data-index="0"]').focus();
        }
        document.getElementById('pinSubmitBtn').disabled = false;
        document.getElementById('pinSubmitBtn').textContent = 'Verify';
    })
    .catch(() => {
        document.getElementById('pinError').textContent = 'Network error. Please try again.';
        document.getElementById('pinError').classList.remove('hidden');
        document.getElementById('pinSubmitBtn').disabled = false;
        document.getElementById('pinSubmitBtn').textContent = 'Verify';
    });
}

function checkPinSessionAndSwitch(url) {
    fetch('{{ route("pos.api.check-pin-session") }}')
    .then(r => r.json())
    .then(data => {
        if (data.verified) {
            window.location.href = url;
        } else {
            openPinModal(() => { window.location.href = url; });
        }
    });
}
</script>
