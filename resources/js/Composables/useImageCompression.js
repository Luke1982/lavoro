export function useImageCompression() {
    function compressImage(file, maxWidth = 1000, maxHeight = 1000, quality = 0.8) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();

            reader.onload = (event) => {
                const img = new Image();
                img.src = event.target.result;

                img.onload = () => {
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');

                    let width = img.width;
                    let height = img.height;

                    if (width > maxWidth || height > maxHeight) {
                        if (width > height) {
                            height = (maxHeight / width) * height;
                            width = maxWidth;
                        } else {
                            width = (maxWidth / height) * width;
                            height = maxHeight;
                        }
                    }

                    canvas.width = width;
                    canvas.height = height;

                    ctx.drawImage(img, 0, 0, width, height);

                    canvas.toBlob((blob) => {
                        resolve(new File([blob], file.name, { type: file.type }));
                    }, file.type, quality);
                };

                img.onerror = (err) => reject(err);
            };

            reader.onerror = (err) => reject(err);
            reader.readAsDataURL(file);
        });
    }

    return { compressImage };
}
