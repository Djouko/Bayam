/* BAYAM Vitrine : menu mobile, recherche, ombre de l'en-tête, apparition au défilement. */
(function () {
	'use strict';

	var racine = document.documentElement;
	var enteteEl = document.getElementById('masthead');
	var nav = document.getElementById('bv-nav');
	var burger = document.querySelector('.bv-burger');
	var recherche = document.getElementById('bv-search');
	var voile = document.querySelector('.bv-scrim');
	if (!enteteEl || !nav) { return; }

	function verrouiller(actif) {
		racine.classList.toggle('bv-lock', actif);
		if (voile) { voile.hidden = !actif; }
	}

	function menu(ouvrir) {
		nav.classList.toggle('is-open', ouvrir);
		burger.setAttribute('aria-expanded', ouvrir ? 'true' : 'false');
		verrouiller(ouvrir);
		if (ouvrir) { fermerRecherche(); }
	}

	function fermerRecherche() {
		if (!recherche || recherche.hidden) { return; }
		recherche.hidden = true;
		document.querySelectorAll('.bv-open-search').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
	}

	function basculerRecherche(e) {
		e.preventDefault();
		if (!recherche) { return; }
		var ouvrir = recherche.hidden;
		menu(false);
		recherche.hidden = !ouvrir;
		document.querySelectorAll('.bv-open-search').forEach(function (b) { b.setAttribute('aria-expanded', ouvrir ? 'true' : 'false'); });
		if (ouvrir) { document.getElementById('bv-search-input').focus(); }
	}

	burger.addEventListener('click', function () { menu(!nav.classList.contains('is-open')); });
	nav.querySelector('.bv-nav__close').addEventListener('click', function () { menu(false); });
	if (voile) { voile.addEventListener('click', function () { menu(false); }); }
	document.querySelectorAll('.bv-open-search').forEach(function (b) { b.addEventListener('click', basculerRecherche); });
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') { menu(false); fermerRecherche(); }
	});

	// Sous-menus : un bouton par entrée parente (utile au tactile, le survol n'existe pas).
	nav.querySelectorAll('.menu-item-has-children').forEach(function (li) {
		var lien = li.querySelector(':scope > a');
		var bouton = document.createElement('button');
		bouton.type = 'button';
		bouton.className = 'bv-sub-toggle';
		bouton.setAttribute('aria-expanded', 'false');
		bouton.setAttribute('aria-label', 'Afficher les sous-catégories de ' + lien.textContent.trim());
		bouton.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
		bouton.addEventListener('click', function () {
			var ouvert = li.classList.toggle('is-open');
			bouton.setAttribute('aria-expanded', ouvert ? 'true' : 'false');
		});
		lien.after(bouton);
	});

	// Ombre sous l'en-tête dès que la page défile.
	function defilement() { enteteEl.classList.toggle('is-scrolled', window.scrollY > 8); }
	window.addEventListener('scroll', defilement, { passive: true });
	defilement();

	// Apparition au défilement ; sans animation si l'utilisateur la réduit ou si l'API manque.
	var sansAnimation = window.matchMedia('(prefers-reduced-motion: reduce)').matches || !('IntersectionObserver' in window);
	document.querySelectorAll('ul.products li.product').forEach(function (carte, i) {
		carte.classList.add('bv-reveal');
		carte.style.setProperty('--i', i % 4);
	});
	var elements = document.querySelectorAll('.bv-reveal');
	if (sansAnimation) {
		elements.forEach(function (el) { el.classList.add('bv-in'); });
		return;
	}
	var observateur = new IntersectionObserver(function (entrees) {
		entrees.forEach(function (entree) {
			if (entree.isIntersecting) {
				entree.target.classList.add('bv-in');
				observateur.unobserve(entree.target);
			}
		});
	}, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });
	elements.forEach(function (el) { observateur.observe(el); });
})();
