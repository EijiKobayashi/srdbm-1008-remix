const form = document.querySelector('#replace');
const loading = document.querySelector('#loading');
const upload = document.querySelector('#sql_upload');
const uploadButton = document.querySelector('#upload-sql');
const uploadLabel = document.querySelector('#upload-label');
const uploadStatus = document.querySelector('#upload-status');
const dropZone = document.querySelector('#drop-zone');
const sqlSelect = document.querySelector('#sql_file');
const chunkedSqlFile = document.querySelector('#chunked_sql_file');
const sourcePrefix = document.querySelector('#source_prefix');
const destinationPrefix = document.querySelector('#destination_prefix');
const inspectionEmpty = document.querySelector('#inspection-empty');
const inspectionLoading = document.querySelector('#inspection-loading');
const inspectionResults = document.querySelector('#inspection-results');
const dryRunButton = form?.querySelector('button[value="dry-run"]');

let inspectionRequest = 0;

const element = (tag, className = '', text = '') => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
};

const resetInspection = (message = 'SQLのアップロード後に、検出したWordPress設定を表示します。') => {
    inspectionRequest++;
    inspectionEmpty.textContent = message;
    inspectionEmpty.className = 'border border-dashed border-wp-line bg-[#fafafa] p-5 text-sm text-wp-muted';
    inspectionEmpty.classList.remove('hidden');
    inspectionLoading.classList.add('hidden');
    inspectionLoading.classList.remove('flex');
    inspectionResults.classList.add('hidden');
    inspectionResults.replaceChildren();
    if (dryRunButton) dryRunButton.disabled = Boolean(upload?.files?.[0]);
};

const settingBlock = (title, description, count) => {
    const block = element('section', 'border-t border-wp-line pt-5 first:border-t-0 first:pt-0');
    const heading = element('div', 'mb-3 flex flex-wrap items-center gap-2');
    heading.append(element('h3', 'font-semibold', title));
    heading.append(element('span', 'rounded-full bg-[#f0f0f1] px-2 py-0.5 text-[11px] text-wp-muted', `${count}件`));
    block.append(heading, element('p', 'mb-4 text-xs leading-5 text-wp-muted', description));
    return block;
};

const renderInspection = (inspection) => {
    inspectionResults.replaceChildren();

    const prefixes = Array.isArray(inspection.table_prefixes) ? inspection.table_prefixes : [];
    const prefixBlock = settingBlock('テーブル接頭辞', 'SQLから検出した正確な接頭辞です。変更する場合は、この値を「変更前」に設定してください。', prefixes.length);
    const prefixList = element('div', 'space-y-2');
    if (prefixes.length === 0) {
        prefixList.append(element('p', 'text-sm text-wp-muted', 'WordPressのテーブル接頭辞は検出されませんでした。'));
    }
    prefixes.forEach((item, index) => {
        const row = element('div', 'flex flex-col gap-3 rounded-sm bg-[#f6f7f7] p-3 sm:flex-row sm:items-center');
        const valueWrap = element('div', 'min-w-0 flex-1');
        valueWrap.append(element('code', 'break-all text-sm font-semibold text-wp-ink', item.value));
        valueWrap.append(element('p', 'mt-1 text-xs text-wp-muted', `WordPress基本テーブル ${Number(item.tables || 0)}件で検出`));
        const applyButton = element('button', 'btn secondary shrink-0', '変更前へ設定');
        applyButton.type = 'button';
        applyButton.addEventListener('click', () => {
            sourcePrefix.value = item.value;
            destinationPrefix.focus();
        });
        row.append(valueWrap, applyButton);
        prefixList.append(row);
        if (index === 0 && sourcePrefix && !sourcePrefix.value) sourcePrefix.placeholder = item.value;
    });
    prefixBlock.append(prefixList);

    const emails = Array.isArray(inspection.admin_emails) ? inspection.admin_emails : [];
    const emailBlock = settingBlock('管理者メールアドレス', '変更する場合だけ右側を書き換えてください。同じ値のままなら変更しません。', emails.length);
    const emailList = element('div', 'space-y-3');
    if (emails.length === 0) {
        emailList.append(element('p', 'text-sm text-wp-muted', '管理者メールアドレスは検出されませんでした。'));
    }
    emails.forEach((item) => {
        const row = element('div', 'grid gap-2 rounded-sm bg-[#f6f7f7] p-3 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] sm:items-center');
        const originalWrap = element('div');
        originalWrap.append(element('p', 'break-all text-sm font-medium', item.value));
        originalWrap.append(element('p', 'mt-1 text-xs text-wp-muted', Array.isArray(item.labels) ? item.labels.join(' / ') : ''));
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'email_original[]';
        hidden.value = item.value;
        const replacement = document.createElement('input');
        replacement.type = 'email';
        replacement.name = 'email_replacement[]';
        replacement.value = item.value;
        replacement.setAttribute('aria-label', `${item.value} の変更後メールアドレス`);
        row.append(hidden, originalWrap, element('span', 'hidden text-wp-muted sm:block', '→'), replacement);
        emailList.append(row);
    });
    emailBlock.append(emailList);

    const paths = Array.isArray(inspection.image_paths) ? inspection.image_paths : [];
    const pathBlock = settingBlock('画像パス', 'SQL内で検出したアップロード基点です。個別画像名ではなく、この基点をまとめて変更します。', paths.length);
    const pathList = element('div', 'space-y-3');
    if (paths.length === 0) {
        pathList.append(element('p', 'text-sm text-wp-muted', '画像パスは検出されませんでした。'));
    }
    paths.forEach((item) => {
        const row = element('div', 'grid gap-2 rounded-sm bg-[#f6f7f7] p-3 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] sm:items-center');
        const originalWrap = element('div');
        originalWrap.append(element('p', 'break-all text-sm font-medium', item.value));
        originalWrap.append(element('p', 'mt-1 text-xs text-wp-muted', `検出 ${Number(item.occurrences || 0).toLocaleString()}箇所`));
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'image_path_original[]';
        hidden.value = item.value;
        const replacement = document.createElement('input');
        replacement.type = 'text';
        replacement.name = 'image_path_replacement[]';
        replacement.value = item.value;
        replacement.setAttribute('aria-label', `${item.value} の変更後画像パス`);
        row.append(hidden, originalWrap, element('span', 'hidden text-wp-muted sm:block', '→'), replacement);
        pathList.append(row);
    });
    pathBlock.append(pathList);

    const groups = Array.isArray(inspection.plugin_groups) ? inspection.plugin_groups : [];
    const pluginCount = new Set(groups.flatMap((group) => (group.plugins || []).map((plugin) => plugin.path))).size;
    const pluginBlock = settingBlock('プラグイン', 'SQL内で確認できたプラグインです。チェックありを有効、チェックなしを無効として変換します。', pluginCount);
    if (groups.length === 0) {
        pluginBlock.append(element('p', 'text-sm text-wp-muted', 'プラグイン設定は検出されませんでした。'));
    }
    groups.forEach((group) => {
        const groupWrap = element('fieldset', 'mb-4 rounded-sm border border-wp-line p-4 last:mb-0');
        groupWrap.append(element('legend', 'px-1 text-sm font-semibold', group.label));
        const present = document.createElement('input');
        present.type = 'hidden';
        present.name = 'plugin_groups_present[]';
        present.value = group.id;
        groupWrap.append(present);
        const list = element('div', 'mt-2 grid gap-2 sm:grid-cols-2');
        (group.plugins || []).forEach((plugin) => {
            const label = element('label', 'flex items-start gap-2 rounded-sm bg-[#f6f7f7] p-3');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.name = `plugins[${group.id}][]`;
            checkbox.value = plugin.path;
            checkbox.checked = Boolean(plugin.active);
            checkbox.className = 'mt-0.5';
            label.append(checkbox, element('span', 'break-all text-sm', plugin.path));
            list.append(label);
        });
        groupWrap.append(list);
        pluginBlock.append(groupWrap);
    });

    inspectionResults.append(prefixBlock, emailBlock, pathBlock, pluginBlock);
    inspectionEmpty.classList.add('hidden');
    inspectionLoading.classList.add('hidden');
    inspectionLoading.classList.remove('flex');
    inspectionResults.classList.remove('hidden');
};

const inspectSql = async (filename) => {
    if (!filename) {
        resetInspection();
        return;
    }
    const requestId = ++inspectionRequest;
    if (dryRunButton) dryRunButton.disabled = true;
    inspectionEmpty.classList.add('hidden');
    inspectionResults.classList.add('hidden');
    inspectionLoading.classList.remove('hidden');
    inspectionLoading.classList.add('flex');
    const data = new FormData();
    data.append('csrf_token', form.elements.csrf_token.value);
    data.append('filename', filename);
    try {
        const response = await fetch('?inspect_sql=1', { method: 'POST', body: data, credentials: 'same-origin' });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message || 'SQLの解析に失敗しました。');
        if (requestId === inspectionRequest) renderInspection(result.inspection || {});
    } catch (error) {
        if (requestId !== inspectionRequest) return;
        inspectionLoading.classList.add('hidden');
        inspectionLoading.classList.remove('flex');
        inspectionEmpty.textContent = error instanceof Error ? error.message : 'SQLの解析に失敗しました。';
        inspectionEmpty.className = 'border border-wp-danger bg-[#fcf0f1] p-5 text-sm text-wp-danger';
    } finally {
        if (requestId === inspectionRequest && dryRunButton) dryRunButton.disabled = false;
    }
};

const setUploadSelection = (file) => {
    chunkedSqlFile.value = '';
    sqlSelect.value = '';
    uploadLabel.textContent = file.name;
    uploadStatus.textContent = `${file.name} を選択しました。「アップロードする」を押してください。`;
    uploadStatus.className = 'text-xs text-[#996800]';
    uploadButton.disabled = false;
    resetInspection('アップロード完了後にWordPress設定を解析します。');
};

upload?.addEventListener('change', () => {
    if (upload.files.length > 0) setUploadSelection(upload.files[0]);
});

sqlSelect?.addEventListener('change', async () => {
    upload.value = '';
    chunkedSqlFile.value = '';
    uploadButton.disabled = true;
    if (sqlSelect.value) {
        uploadLabel.textContent = 'SQLファイルを選択';
        uploadStatus.textContent = 'アップロード済みSQLを選択しています。ドライランへ進めます。';
        uploadStatus.className = 'text-xs font-medium text-wp-success';
        await inspectSql(sqlSelect.value);
    } else {
        uploadStatus.textContent = 'ファイルを選択するとアップロードできます。';
        uploadStatus.className = 'text-xs text-wp-muted';
        resetInspection();
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

    let uploadedFilename = '';
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
                uploadedFilename = result.filename;
                chunkedSqlFile.value = result.filename;
                const option = new Option(`${result.filename} — アップロード完了`, result.filename, true, true);
                sqlSelect.add(option, 1);
            }
        }
        upload.value = '';
        uploadLabel.textContent = file.name;
        uploadStatus.textContent = 'アップロードが完了しました。置換条件を入力してドライランへ進んでください。';
        uploadStatus.className = 'text-xs font-medium text-wp-success';
        loadingTitle.textContent = 'WordPress設定を解析しています';
        await inspectSql(uploadedFilename);
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

if (sqlSelect?.value) inspectSql(sqlSelect.value);
