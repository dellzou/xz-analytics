/**
 * iframe 高度自适应：面板里展开一行、切换筛选后高度会变，需要重新量。
 */
(function () {
	'use strict';

	var frame = document.getElementById('xz-analytics-frame');
	if (!frame) {
		return;
	}

	var lastHeight = 0;

	function syncHeight() {
		var doc;
		try {
			doc = frame.contentDocument || frame.contentWindow.document;
		} catch (e) {
			return; // 跨域或被拒绝访问时放弃，靠 min-height 兜底
		}
		if (!doc || !doc.documentElement) {
			return;
		}
		var h = Math.max(
			doc.documentElement.scrollHeight,
			doc.body ? doc.body.scrollHeight : 0
		);
		if (h > 0 && Math.abs(h - lastHeight) > 2) {
			lastHeight = h;
			frame.style.height = h + 'px';
		}
	}

	frame.addEventListener('load', function () {
		syncHeight();
		// 首屏图表渲染完成后再量一次
		setTimeout(syncHeight, 120);
		setTimeout(syncHeight, 500);
	});

	// 面板内部有筛选、展开等交互，持续校正（成本很低，只在高度变化时才写 DOM）
	var timer = setInterval(syncHeight, 400);
	window.addEventListener('beforeunload', function () {
		clearInterval(timer);
	});
})();
