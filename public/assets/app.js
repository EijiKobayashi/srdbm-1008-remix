const form = document.querySelector('#replace');
const loading = document.querySelector('#loading');
const upload = document.querySelector('#sql_upload');
const uploadLabel = document.querySelector('#upload-label');
const dropZone = document.querySelector('#drop-zone');
const sqlSelect = document.querySelector('#sql_file');

upload?.addEventListener('change', () => {
    if (upload.files.length > 0) {
        uploadLabel.textContent = upload.files[0].name;
        sqlSelect.value = '';
    }
});

for (const eventName of ['dragenter', 'dragover']) {
    dropZone?.addEventListener(eventName, () => dropZone.classList.add('border-wp-blue', 'bg-[#f6f7ff]'));
}
for (const eventName of ['dragleave', 'drop']) {
    dropZone?.addEventListener(eventName, () => dropZone.classList.remove('border-wp-blue', 'bg-[#f6f7ff]'));
}

let chunkUploadRunning = false;

form?.addEventListener('submit', async (event) => {
    if (event.submitter?.value === 'execute') {
        const confirmation = form.querySelector('[name="confirm_backup"]');
        if (!confirmation.checked) {
            event.preventDefault();
            confirmation.focus();
            return;
        }
    }
    const file = upload.files[0];
    if (file && file.size > 786432 && !chunkUploadRunning) {
        event.preventDefault();
        if (file.size > 524288000) {
            uploadLabel.textContent = '500MB以下のSQLを選択してください';
            return;
        }
        chunkUploadRunning = true;
        loading.classList.remove('hidden');
        loading.classList.add('flex');
        const status = loading.querySelector('p');
        const chunkSize = 786432;
        const uploadId = Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');
        let offset = 0;
        try {
            while (offset < file.size) {
                const end = Math.min(offset + chunkSize, file.size);
                const data = new FormData();
                data.append('csrf_token', form.elements.csrf_token.value);
                data.append('upload_id', uploadId);
                data.append('filename', file.name);
                data.append('offset', String(offset));
                data.append('is_last', end === file.size ? '1' : '0');
                data.append('chunk', file.slice(offset, end), 'chunk.part');
                status.textContent = `SQLをアップロードしています（${Math.round(end / file.size * 100)}%）`;
                const response = await fetch('?chunk_upload=1', { method: 'POST', body: data, credentials: 'same-origin' });
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.message || 'アップロードに失敗しました。');
                offset = end;
                if (result.filename) form.elements.chunked_sql_file.value = result.filename;
            }
            upload.value = '';
            form.requestSubmit(event.submitter);
        } catch (error) {
            loading.classList.add('hidden');
            loading.classList.remove('flex');
            uploadLabel.textContent = error.message;
            chunkUploadRunning = false;
        }
        return;
    }
    loading.classList.remove('hidden');
    loading.classList.add('flex');
});
