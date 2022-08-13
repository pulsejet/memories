(async () => {
    const res = await fetch('/apps/betterphotos/api/list');
    const data = await res.json();
    for (const p of data) {
        const img = document.createElement('img');
        img.classList = 'photo';
        img.src = `/core/preview?fileId=${p.file_id}&x=250&y=250`;
        document.getElementById('photos').appendChild(img);
    }
})();

