const form = document.querySelector('#replace');
const loading = document.querySelector('#loading');
const upload = document.querySelector('#sql_upload');
const uploadButton = document.querySelector('#upload-sql');
const uploadLabel = document.querySelector('#upload-label');
const uploadStatus = document.querySelector('#upload-status');
const dropZone = document.querySelector('#drop-zone');
const sqlSelect = document.querySelector('#sql_file');
const chunkedSqlFile = document.querySelector('#chunked_sql_file');

const setUploadSelection = (file) => {
    chunkedSqlFile.value = '';
    sqlSelect.value = '';
    uploadLabel.textContent = file.name;
    uploadStatus.textContent = `${file.name} を選択しました。「アップロードする」を押してください。`;
    uploadStatus.className = 'text-xs text-[#996800]';
    uploadButton.disabled = false;
};

upload?.addEventListener('change', () => {
    if (upload.files.length > 0) setUploadSelection(upload.files[0]);
});

sqlSelect?.addEventListener('change', () => {
    upload.value = '';
    chunkedSqlFile.value = '';
    uploadButton.disabled = true;
    if (sqlSelect.value) {
        uploadLabel.textContent = 'SQLファイルを選択';
        uploadStatus.textContent = 'アップロード済みSQLを選択しています。ドライランへ進めます。';
        uploadStatus.className = 'text-xs font-medium text-wp-success';
    } else {
        uploadStatus.textContent = 'ファイルを選択するとアップロードできます。';
        uploadStatus.className = 'text-xs text-wp-muted';
    }
});

for (const eventName of ['dragenter', 'dragover']) {
    dropZone?.addEventListener(eventName, (event) => {
        event.preventDefault();
        event.stopPropagation();
        dropZone.classList.add('border-wp-blue', 'bg-[#f6f7ff]');
    });
}

dropZone?.addEventListener('dragleave', (event) => {
    event.preventDefault();
    event.stopPropagation();
    dropZone.classList.remove('border-wp-blue', 'bg-[#f6f7ff]');
});

dropZone?.addEventListener('drop', (event) => {
    event.preventDefault();
    event.stopPropagation();
    dropZone.classList.remove('border-wp-blue', 'bg-[#f6f7ff]');

    const file = event.dataTransfer?.files?.[0];
    if (!file) return;
    if (!file.name.toLowerCase().endsWith('.sql')) {
        uploadLabel.textContent = '.sql ファイルを選択してください';
        uploadStatus.textContent = 'アップロードできるファイルは .sql のみです。';
        uploadStatus.className = 'text-xs font-medium text-wp-danger';
        return;
    }

    const transfer = new DataTransfer();
    transfer.items.add(file);
    upload.files = transfer.files;
    setUploadSelection(file);
});

for (const eventName of ['dragover', 'drop']) {
    document.addEventListener(eventName, (event) => event.preventDefault());
}

let chunkUploadRunning = false;

uploadButton?.addEventListener('click', async () => {
    const file = upload.files[0];
    if (!file || chunkUploadRunning) return;
    if (!file.name.toLowerCase().endsWith('.sql')) {
        uploadStatus.textContent = 'アップロードできるファイルは .sql のみです。';
        uploadStatus.className = 'text-xs font-medium text-wp-danger';
        return;
    }
    if (file.size <= 0 || file.size > 524288000) {
        uploadStatus.textContent = 'SQLファイルは1バイト以上500MB以下にしてください。';
        uploadStatus.className = 'text-xs font-medium text-wp-danger';
        return;
    }

    chunkUploadRunning = true;
    uploadButton.disabled = true;
    loading.classList.remove('hidden');
    loading.classList.add('flex');
    const loadingTitle = loading.querySelector('p');
    const chunkSize = 786432;
    const uploadId = Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, '0')).join('');
    let offset = 0;

    try {
        while (offset < file.size) {
            const end = Math.min(offset + chunkSize, file.size);
            const percent = Math.round(end / file.size * 100);
            const data = new FormData();
            data.append('csrf_token', form.elements.csrf_token.value);
            data.append('upload_id', uploadId);
            data.append('filename', file.name);
            data.append('offset', String(offset));
            data.append('is_last', end === file.size ? '1' : '0');
            data.append('chunk', file.slice(offset, end), 'chunk.part');
            loadingTitle.textContent = `SQLをアップロードしています（${percent}%）`;
            uploadStatus.textContent = `アップロード中… ${percent}%`;
            const response = await fetch('?chunk_upload=1', { method: 'POST', body: data, credentials: 'same-origin' });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || 'アップロードに失敗しました。');
            offset = end;
            if (result.filename) {
                chunkedSqlFile.value = result.filename;
                const option = new Option(`${result.filename} — アップロード完了`, result.filename, true, true);
                sqlSelect.add(option, 1);
            }
        }
        upload.value = '';
        uploadLabel.textContent = file.name;
        uploadStatus.textContent = 'アップロードが完了しました。置換条件を入力してドライランへ進んでください。';
        uploadStatus.className = 'text-xs font-medium text-wp-success';
    } catch (error) {
        chunkedSqlFile.value = '';
        uploadStatus.textContent = error instanceof Error ? error.message : 'アップロードに失敗しました。';
        uploadStatus.className = 'text-xs font-medium text-wp-danger';
        uploadButton.disabled = false;
    } finally {
        loading.classList.add('hidden');
        loading.classList.remove('flex');
        loadingTitle.textContent = 'SQLを処理しています';
        chunkUploadRunning = false;
    }
});

form?.addEventListener('submit', (event) => {
    if (upload.files[0] && !chunkedSqlFile.value) {
        event.preventDefault();
        uploadStatus.textContent = '先に「アップロードする」を押してください。';
        uploadStatus.className = 'text-xs font-medium text-wp-danger';
        uploadButton.focus();
        return;
    }
    if (!sqlSelect.value && !chunkedSqlFile.value) {
        event.preventDefault();
        uploadStatus.textContent = 'SQLファイルをアップロードするか、アップロード済みSQLを選択してください。';
        uploadStatus.className = 'text-xs font-medium text-wp-danger';
        document.querySelector('#files')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    loading.classList.remove('hidden');
    loading.classList.add('flex');
});

document.querySelectorAll('[data-processing-form]').forEach((processingForm) => {
    processingForm.addEventListener('submit', () => {
        loading.classList.remove('hidden');
        loading.classList.add('flex');
    });
});
