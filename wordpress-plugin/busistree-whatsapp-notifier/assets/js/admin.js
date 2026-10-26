function bwaCopy(elementId) {
    var copyText = document.getElementById(elementId);
    if (!copyText) return;
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value).then(function() {
        alert('Copied to clipboard: ' + copyText.value);
    }).catch(function() {
        document.execCommand('copy');
        alert('Copied to clipboard: ' + copyText.value);
    });
}

function bwaInsertTag(tag) {
    navigator.clipboard.writeText(tag).then(function() {
        // Show gentle indicator
        var note = document.createElement('div');
        note.textContent = 'Tag ' + tag + ' copied to clipboard! Paste it into any template.';
        note.style.position = 'fixed';
        note.style.bottom = '20px';
        note.style.right = '20px';
        note.style.background = '#0f172a';
        note.style.color = '#fff';
        note.style.padding = '10px 16px';
        note.style.borderRadius = '6px';
        note.style.zIndex = 99999;
        document.body.appendChild(note);
        setTimeout(function() {
            note.remove();
        }, 2500);
    });
}
