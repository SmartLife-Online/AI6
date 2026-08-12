(() => {
    'use strict';

    const expectedStyleSha256 = 'wHM+htXdtkideW9K/pE8sHwN7LYOKJTCZfrrEvY5Qvg=';
    const expectedStyleCss = atob('LyogTWFrZSBjbGlja3MgcGFzcy10aHJvdWdoICovCgogICAgI25wcm9ncmVzcyB7CiAgICAgIHBvaW50ZXItZXZlbnRzOiBub25lOwogICAgfQoKICAgICNucHJvZ3Jlc3MgLmJhciB7CiAgICAgIGJhY2tncm91bmQ6IHZhcigtLWxpdmV3aXJlLXByb2dyZXNzLWJhci1jb2xvciwgIzI5ZCk7CgogICAgICBwb3NpdGlvbjogZml4ZWQ7CiAgICAgIHotaW5kZXg6IDEwMzE7CiAgICAgIHRvcDogMDsKICAgICAgbGVmdDogMDsKCiAgICAgIHdpZHRoOiAxMDAlOwogICAgICBoZWlnaHQ6IDJweDsKICAgIH0KCiAgICAvKiBGYW5jeSBibHVyIGVmZmVjdCAqLwogICAgI25wcm9ncmVzcyAucGVnIHsKICAgICAgZGlzcGxheTogYmxvY2s7CiAgICAgIHBvc2l0aW9uOiBhYnNvbHV0ZTsKICAgICAgcmlnaHQ6IDBweDsKICAgICAgd2lkdGg6IDEwMHB4OwogICAgICBoZWlnaHQ6IDEwMCU7CiAgICAgIGJveC1zaGFkb3c6IDAgMCAxMHB4IHZhcigtLWxpdmV3aXJlLXByb2dyZXNzLWJhci1jb2xvciwgIzI5ZCksIDAgMCA1cHggdmFyKC0tbGl2ZXdpcmUtcHJvZ3Jlc3MtYmFyLWNvbG9yLCAjMjlkKTsKICAgICAgb3BhY2l0eTogMS4wOwoKICAgICAgLXdlYmtpdC10cmFuc2Zvcm06IHJvdGF0ZSgzZGVnKSB0cmFuc2xhdGUoMHB4LCAtNHB4KTsKICAgICAgICAgIC1tcy10cmFuc2Zvcm06IHJvdGF0ZSgzZGVnKSB0cmFuc2xhdGUoMHB4LCAtNHB4KTsKICAgICAgICAgICAgICB0cmFuc2Zvcm06IHJvdGF0ZSgzZGVnKSB0cmFuc2xhdGUoMHB4LCAtNHB4KTsKICAgIH0KCiAgICAvKiBSZW1vdmUgdGhlc2UgdG8gZ2V0IHJpZCBvZiB0aGUgc3Bpbm5lciAqLwogICAgI25wcm9ncmVzcyAuc3Bpbm5lciB7CiAgICAgIGRpc3BsYXk6IGJsb2NrOwogICAgICBwb3NpdGlvbjogZml4ZWQ7CiAgICAgIHotaW5kZXg6IDEwMzE7CiAgICAgIHRvcDogMTVweDsKICAgICAgcmlnaHQ6IDE1cHg7CiAgICB9CgogICAgI25wcm9ncmVzcyAuc3Bpbm5lci1pY29uIHsKICAgICAgd2lkdGg6IDE4cHg7CiAgICAgIGhlaWdodDogMThweDsKICAgICAgYm94LXNpemluZzogYm9yZGVyLWJveDsKCiAgICAgIGJvcmRlcjogc29saWQgMnB4IHRyYW5zcGFyZW50OwogICAgICBib3JkZXItdG9wLWNvbG9yOiB2YXIoLS1saXZld2lyZS1wcm9ncmVzcy1iYXItY29sb3IsICMyOWQpOwogICAgICBib3JkZXItbGVmdC1jb2xvcjogdmFyKC0tbGl2ZXdpcmUtcHJvZ3Jlc3MtYmFyLWNvbG9yLCAjMjlkKTsKICAgICAgYm9yZGVyLXJhZGl1czogNTAlOwoKICAgICAgLXdlYmtpdC1hbmltYXRpb246IG5wcm9ncmVzcy1zcGlubmVyIDQwMG1zIGxpbmVhciBpbmZpbml0ZTsKICAgICAgICAgICAgICBhbmltYXRpb246IG5wcm9ncmVzcy1zcGlubmVyIDQwMG1zIGxpbmVhciBpbmZpbml0ZTsKICAgIH0KCiAgICAubnByb2dyZXNzLWN1c3RvbS1wYXJlbnQgewogICAgICBvdmVyZmxvdzogaGlkZGVuOwogICAgICBwb3NpdGlvbjogcmVsYXRpdmU7CiAgICB9CgogICAgLm5wcm9ncmVzcy1jdXN0b20tcGFyZW50ICNucHJvZ3Jlc3MgLnNwaW5uZXIsCiAgICAubnByb2dyZXNzLWN1c3RvbS1wYXJlbnQgI25wcm9ncmVzcyAuYmFyIHsKICAgICAgcG9zaXRpb246IGFic29sdXRlOwogICAgfQoKICAgIEAtd2Via2l0LWtleWZyYW1lcyBucHJvZ3Jlc3Mtc3Bpbm5lciB7CiAgICAgIDAlICAgeyAtd2Via2l0LXRyYW5zZm9ybTogcm90YXRlKDBkZWcpOyB9CiAgICAgIDEwMCUgeyAtd2Via2l0LXRyYW5zZm9ybTogcm90YXRlKDM2MGRlZyk7IH0KICAgIH0KICAgIEBrZXlmcmFtZXMgbnByb2dyZXNzLXNwaW5uZXIgewogICAgICAwJSAgIHsgdHJhbnNmb3JtOiByb3RhdGUoMGRlZyk7IH0KICAgICAgMTAwJSB7IHRyYW5zZm9ybTogcm90YXRlKDM2MGRlZyk7IH0KICAgIH0KICAgIA==');
    const head = document.head;

    if (
        head === null
        || typeof HTMLStyleElement === 'undefined'
    ) {
        return;
    }

    const appendChild = head.appendChild;
    let armed = true;

    const restore = () => {
        if (!armed) {
            return;
        }

        armed = false;

        if (head.appendChild === guardedAppendChild) {
            delete head.appendChild;

            if (head.appendChild === guardedAppendChild) {
                head.appendChild = appendChild;
            }
        }
    };

    const appendUnlessConnected = (node) => {
        if (!node.isConnected) {
            appendChild.call(head, node);
        }
    };

    function guardedAppendChild(node) {
        const css = node instanceof HTMLStyleElement ? node.textContent : null;
        const isLivewireProgressStyle = typeof css === 'string'
            && css.startsWith('/* Make clicks pass-through */')
            && css.includes('#nprogress .bar')
            && css.includes('--livewire-progress-bar-color')
            && css.includes('@keyframes nprogress-spinner');

        if (!isLivewireProgressStyle) {
            return appendChild.call(this, node);
        }

        restore();

        if (globalThis.crypto?.subtle === undefined || typeof TextEncoder === 'undefined') {
            if (css !== expectedStyleCss) {
                appendUnlessConnected(node);
            }

            return node;
        }

        globalThis.crypto.subtle.digest('SHA-256', new TextEncoder().encode(css))
            .then((digest) => {
                const actual = btoa(String.fromCharCode(...new Uint8Array(digest)));

                if (actual !== expectedStyleSha256) {
                    appendUnlessConnected(node);
                }
            })
            .catch(() => {
                if (css !== expectedStyleCss) {
                    appendUnlessConnected(node);
                }
            });

        return node;
    }

    head.appendChild = guardedAppendChild;
    window.addEventListener('DOMContentLoaded', restore, { once: true });
})();
