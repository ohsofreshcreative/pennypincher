import Swiper from 'swiper';
import { Navigation } from 'swiper/modules';

import 'swiper/css';

const initvalues = () => {
	const valuess = document.querySelectorAll('.values-swiper');
	if (!valuess.length) {
		return;
	}

	valuess.forEach((values) => {
		new Swiper(values, {
			modules: [Navigation],
			grabCursor: true,
			slidesPerView: 'auto',
			spaceBetween: 24,
			navigation: {
				prevEl: values.closest('section').querySelector('.__prev'),
				nextEl: values.closest('section').querySelector('.__next'),
			},
		});
	});
};

initvalues();