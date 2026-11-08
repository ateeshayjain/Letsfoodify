<?php
/**
 * Delivery date by PIN code, on the product page.
 *
 * WHY THIS EXISTS. Principle 3 is "no surprises after the cart", and the most
 * common surprise in Indian D2C food is not the price but the date: a buyer who
 * needs it by the weekend finds out at the last screen — or after paying — that
 * it ships Monday. Amazon and Blinkit answer "when?" before "add to cart"; this
 * page answered it with one sentence for the whole country.
 *
 * WHERE THE ANSWER IS COMPUTED — IN THE BROWSER, FROM THE SAME RULES.
 * The zone rules travel to the page as JSON and the date arithmetic runs in
 * foodify_delivery_script(). The PHP twin below (foodify_delivery_answer) is the
 * tested reference, and tests/fixtures/delivery-vectors.json is a set of cases
 * BOTH must satisfy (php tests/lane-a-test.php; node tests/delivery-vectors.js).
 * Chosen over a REST endpoint that computes the date server-side because:
 *  1. The answer depends on the CLOCK (the dispatch cut-off). A server-rendered
 *     date on a page-cached product page is frozen at the moment the cache was
 *     filled — "ships today" read at 9 pm is a false statement. The browser's
 *     clock, converted to IST, is current on every view.
 *  2. The only server round trip is the PIN → state lookup checkout already
 *     uses (foodify/v1/pin, cached a week). A compute endpoint would be a
 *     second, uncacheable request per check, against the WP-04 request budget.
 *  3. The clickable prototype has no server. It runs the same script by giving
 *     it a lookup function (window.foodifyPinLookup) instead of a URL.
 * The cost: a customer whose phone clock is wrong sees a date off by that much.
 * Checkout states the real shipping before payment, so this is a preview.
 *
 * NOTHING HERE IS A BUSINESS VALUE. Zones, transit days, the cut-off hour and
 * the non-dispatch days come from the option `foodify_delivery_zones` (shape:
 * foodify_delivery_normalise_config). Unset or malformed → the widget is not
 * printed at all, and wp-admin names the option.
 *
 * WHAT IT DOES NOT CLAIM. No COD-by-PIN line: payments.php does not restrict
 * COD by area, so "COD not available here" would be a promise checkout does
 * not keep. No countdown: "Order by 2 pm to ship today" is stated as a fact,
 * once, and only while it is true.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Pure — tested in tests/lane-a-test.php without WordPress.
 * ---------------------------------------------------------------------- */

const FOODIFY_DELIVERY_TZ       = 'Asia/Kolkata';
const FOODIFY_DELIVERY_MAX_DAYS = 60;   // sanity bound on any configured transit time

/**
 * Validate the option into the exact shape the page (and the JS) receives.
 *
 *   zones                 list of {label, states?:[WC state codes], pin_prefixes?:[digits],
 *                         min_days:int, max_days:int} — REQUIRED, at least one
 *   cutoff_hour           0–23, IST; an order before it ships the same day — REQUIRED
 *   no_dispatch_weekdays  ISO weekdays 1=Mon … 7=Sun with no dispatch — REQUIRED,
 *                         may be [], because "ships every day" is a claim and an
 *                         absent key is not consent to make it
 *   no_delivery_weekdays  ISO weekdays not counted as transit days (default [])
 *   holidays              'YYYY-MM-DD' dates with no dispatch (default [])
 *
 * Normalising a normalised config returns it unchanged (tested), so the JSON on
 * the page and the PHP reference read identical rules.
 *
 * @param mixed $raw
 */
function foodify_delivery_normalise_config( $raw ): ?array {
	if ( ! is_array( $raw ) || empty( $raw['zones'] ) || ! is_array( $raw['zones'] ) ) {
		return null;
	}
	if ( ! isset( $raw['cutoff_hour'] ) || ! is_numeric( $raw['cutoff_hour'] ) ) {
		return null;
	}
	$cutoff = (int) $raw['cutoff_hour'];
	if ( $cutoff < 0 || $cutoff > 23 || (float) $raw['cutoff_hour'] !== (float) $cutoff ) {
		return null;
	}
	if ( ! array_key_exists( 'no_dispatch_weekdays', $raw ) || ! is_array( $raw['no_dispatch_weekdays'] ) ) {
		return null;
	}
	$days = static function ( $list ): ?array {
		if ( ! is_array( $list ) ) {
			return null;
		}
		$out = [];
		foreach ( $list as $d ) {
			if ( ! is_numeric( $d ) || (int) $d < 1 || (int) $d > 7 ) {
				return null;
			}
			$out[] = (int) $d;
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return $out;
	};
	$no_dispatch = $days( $raw['no_dispatch_weekdays'] );
	$no_delivery = $days( $raw['no_delivery_weekdays'] ?? [] );
	if ( null === $no_dispatch || null === $no_delivery || 7 === count( $no_dispatch ) || 7 === count( $no_delivery ) ) {
		return null;   // a week with no dispatch day has no delivery date
	}

	$holidays = [];
	foreach ( (array) ( $raw['holidays'] ?? [] ) as $h ) {
		$h = trim( (string) $h );
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $h );
		if ( ! $d || $d->format( 'Y-m-d' ) !== $h ) {
			return null;   // a typo in a holiday is a wrong date on the page
		}
		$holidays[] = $h;
	}
	$holidays = array_values( array_unique( $holidays ) );
	sort( $holidays );

	$zones = [];
	foreach ( $raw['zones'] as $z ) {
		if ( ! is_array( $z ) ) {
			return null;
		}
		$label = trim( (string) ( $z['label'] ?? '' ) );
		$min   = $z['min_days'] ?? null;
		$max   = $z['max_days'] ?? null;
		if ( '' === $label || ! is_numeric( $min ) || ! is_numeric( $max ) ) {
			return null;
		}
		$min = (int) $min;
		$max = (int) $max;
		if ( $min < 0 || $max < $min || $max > FOODIFY_DELIVERY_MAX_DAYS ) {
			return null;
		}
		$states = [];
		foreach ( (array) ( $z['states'] ?? [] ) as $s ) {
			$s = strtoupper( trim( (string) $s ) );
			if ( ! preg_match( '/^[A-Z]{2,3}$/', $s ) ) {
				return null;
			}
			$states[] = $s;
		}
		$prefixes = [];
		foreach ( (array) ( $z['pin_prefixes'] ?? [] ) as $p ) {
			$p = trim( (string) $p );
			if ( ! preg_match( '/^[1-9]\d{0,5}$/', $p ) ) {
				return null;
			}
			$prefixes[] = $p;
		}
		if ( ! $states && ! $prefixes ) {
			return null;   // a zone that matches nothing is a config error, not a zone
		}
		$zones[] = [
			'label'        => $label,
			'states'       => array_values( array_unique( $states ) ),
			'pin_prefixes' => array_values( array_unique( $prefixes ) ),
			'min_days'     => $min,
			'max_days'     => $max,
		];
	}

	return [
		'zones'                => $zones,
		'cutoff_hour'          => $cutoff,
		'no_dispatch_weekdays' => $no_dispatch,
		'no_delivery_weekdays' => $no_delivery,
		'holidays'             => $holidays,
	];
}

/** A PIN is six digits and never starts with 0 (India Post). */
function foodify_delivery_pin_ok( string $pin ): bool {
	return 1 === preg_match( '/^[1-9]\d{5}$/', $pin );
}

/**
 * Which zone serves this PIN. A PIN-prefix match beats a state match (it is the
 * more specific rule — "2013xx" inside "UP"); among prefixes the longest wins;
 * among states, the first zone listed wins.
 */
function foodify_delivery_zone( string $pin, ?string $state, array $zones ): ?array {
	$best     = null;
	$best_len = 0;
	foreach ( $zones as $z ) {
		foreach ( $z['pin_prefixes'] as $p ) {
			$len = strlen( $p );
			if ( $len > $best_len && 0 === strncmp( $pin, $p, $len ) ) {
				$best     = $z;
				$best_len = $len;
			}
		}
	}
	if ( null !== $best ) {
		return $best;
	}
	$state = strtoupper( trim( (string) $state ) );
	if ( '' === $state ) {
		return null;
	}
	foreach ( $zones as $z ) {
		if ( in_array( $state, $z['states'], true ) ) {
			return $z;
		}
	}
	return null;
}

/** "Thu 12 Nov" — the one date format the widget prints. */
function foodify_delivery_day( DateTimeImmutable $d ): string {
	return $d->format( 'D j M' );
}

/** "Delivers Thu 12 – Sat 14 Nov" / "Delivers Sat 29 Nov – Tue 2 Dec" / "Delivers Thu 12 Nov". */
function foodify_delivery_label( DateTimeImmutable $from, DateTimeImmutable $to ): string {
	if ( $from->format( 'Y-m-d' ) === $to->format( 'Y-m-d' ) ) {
		return 'Delivers ' . foodify_delivery_day( $from );
	}
	if ( $from->format( 'Y-m' ) === $to->format( 'Y-m' ) ) {
		return 'Delivers ' . $from->format( 'D j' ) . ' – ' . foodify_delivery_day( $to );
	}
	return 'Delivers ' . foodify_delivery_day( $from ) . ' – ' . foodify_delivery_day( $to );
}

/** 14 → "2 pm", 9 → "9 am", 12 → "12 noon", 0 → "12 am". */
function foodify_delivery_hour( int $h ): string {
	if ( 12 === $h ) {
		return '12 noon';
	}
	return ( 0 === $h % 12 ? 12 : $h % 12 ) . ( $h < 12 ? ' am' : ' pm' );
}

/**
 * The date range, or null when there is no honest answer (bad PIN, no zone,
 * unusable config).
 *
 * Dispatch: today if before the cut-off and today is a dispatch day, else the
 * next dispatch day. Transit: min/max days counted from dispatch, skipping
 * no-delivery weekdays. All in IST, whatever the server's timezone.
 *
 * @return array{from:string,to:string,label:string,zone:string,dispatch:string,detail:string}|null
 */
function foodify_delivery_estimate( string $pin, ?string $state, array $cfg, DateTimeImmutable $now ): ?array {
	$cfg = foodify_delivery_normalise_config( $cfg );
	if ( null === $cfg || ! foodify_delivery_pin_ok( $pin ) ) {
		return null;
	}
	$zone = foodify_delivery_zone( $pin, $state, $cfg['zones'] );
	if ( null === $zone ) {
		return null;
	}

	$local = $now->setTimezone( new DateTimeZone( FOODIFY_DELIVERY_TZ ) );
	$today = $local->setTime( 0, 0 );
	$day   = (int) $local->format( 'G' ) >= $cfg['cutoff_hour'] ? $today->modify( '+1 day' ) : $today;

	$is_dispatch = static fn( DateTimeImmutable $d ): bool =>
		! in_array( (int) $d->format( 'N' ), $cfg['no_dispatch_weekdays'], true )
		&& ! in_array( $d->format( 'Y-m-d' ), $cfg['holidays'], true );
	$guard = 0;
	while ( ! $is_dispatch( $day ) ) {
		$day = $day->modify( '+1 day' );
		if ( ++$guard > 400 ) {
			return null;   // a holiday list covering a year is not a delivery date
		}
	}
	$dispatch = $day;

	$skip = $cfg['no_delivery_weekdays'];
	$add  = static function ( DateTimeImmutable $d, int $n ) use ( $skip ): DateTimeImmutable {
		while ( $n > 0 ) {
			$d = $d->modify( '+1 day' );
			if ( ! in_array( (int) $d->format( 'N' ), $skip, true ) ) {
				$n--;
			}
		}
		while ( in_array( (int) $d->format( 'N' ), $skip, true ) ) {
			$d = $d->modify( '+1 day' );
		}
		return $d;
	};
	$from = $add( $dispatch, $zone['min_days'] );
	$to   = $add( $dispatch, $zone['max_days'] );

	$detail = $dispatch->format( 'Y-m-d' ) === $today->format( 'Y-m-d' )
		? 'Order by ' . foodify_delivery_hour( $cfg['cutoff_hour'] ) . ' to ship today.'
		: 'Ships ' . foodify_delivery_day( $dispatch ) . '.';

	return [
		'from'     => $from->format( 'Y-m-d' ),
		'to'       => $to->format( 'Y-m-d' ),
		'label'    => foodify_delivery_label( $from, $to ),
		'zone'     => $zone['label'],
		'dispatch' => $dispatch->format( 'Y-m-d' ),
		'detail'   => $detail,
	];
}

/**
 * What the widget says, for every case. `lookup` is the site's PIN table answer
 * ({city, state} — state '' when a PIN spans two states) or null when the PIN
 * is not in the table.
 *
 * status: ok | invalid | unknown | unconfirmed | unserviceable | unconfigured
 *
 * @return array{status:string,message:string,detail:string,estimate:?array}
 */
function foodify_delivery_answer( string $pin, ?array $lookup, array $cfg, DateTimeImmutable $now ): array {
	$out = static fn( string $s, string $m, string $d = '', ?array $e = null ): array =>
		[ 'status' => $s, 'message' => $m, 'detail' => $d, 'estimate' => $e ];

	$pin = (string) preg_replace( '/\D/', '', $pin );
	if ( null === foodify_delivery_normalise_config( $cfg ) ) {
		return $out( 'unconfigured', '' );
	}
	if ( ! foodify_delivery_pin_ok( $pin ) ) {
		return $out( 'invalid', 'Enter a 6-digit PIN code.' );
	}
	$state = null === $lookup ? null : (string) ( $lookup['state'] ?? '' );
	$city  = null === $lookup ? '' : trim( (string) ( $lookup['city'] ?? '' ) );
	$est   = foodify_delivery_estimate( $pin, $state, $cfg, $now );

	if ( null !== $est ) {
		return $out( 'ok', $est['label'], ( '' !== $city ? 'To ' . $city . ' · ' : '' ) . $est['detail'], $est );
	}
	if ( null === $lookup ) {
		return $out( 'unknown', "We couldn't find PIN code {$pin}.", 'Please check the number.' );
	}
	if ( '' === $state ) {
		return $out( 'unconfirmed', "We can't confirm delivery to {$pin} here.", 'Checkout shows it before you pay.' );
	}
	return $out( 'unserviceable', "Sorry, we don't deliver to {$pin}" . ( '' !== $city ? " ({$city})" : '' ) . ' yet.' );
}

/**
 * The widget's markup. Printed `hidden`: without the script it cannot answer,
 * and a field that does nothing is worse than no field. The script un-hides it.
 */
function foodify_delivery_widget_html( array $cfg, string $pin_url ): string {
	$cfg = foodify_delivery_normalise_config( $cfg );
	if ( null === $cfg ) {
		return '';
	}
	$e = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES );
	return '<div class="fd-deliver" id="fd-deliver" data-fd-deliver hidden'
		. ' data-pin-url="' . $e( $pin_url ) . '"'
		. ' data-config="' . $e( (string) json_encode( $cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '">'
		. '<form class="fd-deliver__form" novalidate>'
		. '<label class="fd-deliver__label" for="fd-deliver-pin">Check delivery</label>'
		. '<span class="fd-deliver__row">'
		. '<input class="fd-deliver__input" id="fd-deliver-pin" name="pin" type="text" inputmode="numeric" pattern="[1-9][0-9]{5}" maxlength="6" autocomplete="postal-code" placeholder="PIN code" aria-describedby="fd-deliver-out">'
		. '<button class="fd-deliver__go" type="submit">Check</button>'
		. '</span></form>'
		. '<p class="fd-deliver__out" id="fd-deliver-out" role="status" aria-live="polite"></p>'
		. '</div>';
}

/**
 * The widget's script, returned as source so the prototype embeds the SAME
 * code. Exposes window.foodifyDelivery.answer(pin, lookup, cfg, nowMs) — the
 * JS twin of foodify_delivery_answer(), checked against the shared vectors —
 * and binds every [data-fd-deliver] on the page.
 *
 * Lookup: window.foodifyPinLookup(pin) → {city,state}|null (or a Promise of it)
 * when the page defines one (the prototype), else GET data-pin-url + pin (the
 * site's own table, foodify/v1/pin). The checked PIN is remembered in
 * localStorage under `foodify_pin` and pre-filled on the next product page and
 * at checkout (foodify_pin_prefill_script).
 */
function foodify_delivery_script(): string {
	return <<<'JS'
(function(w){
  var DAYS=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'],MON=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],KEY='foodify_pin';
  function pinOk(p){return /^[1-9]\d{5}$/.test(p);}
  function ymd(d){var m=d.getUTCMonth()+1,x=d.getUTCDate();return d.getUTCFullYear()+'-'+(m<10?'0':'')+m+'-'+(x<10?'0':'')+x;}
  function iso(d){return d.getUTCDay()||7;}
  function plus(d,n){return new Date(Date.UTC(d.getUTCFullYear(),d.getUTCMonth(),d.getUTCDate()+n));}
  function day(d){return DAYS[d.getUTCDay()]+' '+d.getUTCDate()+' '+MON[d.getUTCMonth()];}
  function label(f,t){
    if(ymd(f)===ymd(t))return 'Delivers '+day(f);
    if(f.getUTCFullYear()===t.getUTCFullYear()&&f.getUTCMonth()===t.getUTCMonth())return 'Delivers '+DAYS[f.getUTCDay()]+' '+f.getUTCDate()+' – '+day(t);
    return 'Delivers '+day(f)+' – '+day(t);
  }
  function hour(h){return h===12?'12 noon':((h%12)||12)+(h<12?' am':' pm');}
  function has(a,v){return (a||[]).indexOf(v)>-1;}
  function zone(pin,state,zones){
    var best=null,len=0,i,j,p;
    for(i=0;i<zones.length;i++)for(j=0;j<(zones[i].pin_prefixes||[]).length;j++){p=zones[i].pin_prefixes[j];if(p.length>len&&pin.indexOf(p)===0){best=zones[i];len=p.length;}}
    if(best)return best;
    state=String(state||'').trim().toUpperCase();if(!state)return null;
    for(i=0;i<zones.length;i++)if(has(zones[i].states,state))return zones[i];
    return null;
  }
  function estimate(pin,state,cfg,now){
    if(!cfg||!cfg.zones||!pinOk(pin))return null;
    var z=zone(pin,state,cfg.zones);if(!z)return null;
    var ist=new Date(now+330*60000),today=new Date(Date.UTC(ist.getUTCFullYear(),ist.getUTCMonth(),ist.getUTCDate())),d=today,g=0;
    if(ist.getUTCHours()>=cfg.cutoff_hour)d=plus(d,1);
    while(has(cfg.no_dispatch_weekdays,iso(d))||has(cfg.holidays,ymd(d))){d=plus(d,1);if(++g>400)return null;}
    var skip=cfg.no_delivery_weekdays||[];
    function add(s,n){while(n>0){s=plus(s,1);if(!has(skip,iso(s)))n--;}while(has(skip,iso(s)))s=plus(s,1);return s;}
    var f=add(d,z.min_days),t=add(d,z.max_days);
    return {from:ymd(f),to:ymd(t),label:label(f,t),zone:z.label,dispatch:ymd(d),
      detail:ymd(d)===ymd(today)?'Order by '+hour(cfg.cutoff_hour)+' to ship today.':'Ships '+day(d)+'.'};
  }
  function answer(pin,lookup,cfg,now){
    function o(s,m,d,e){return {status:s,message:m,detail:d||'',estimate:e||null};}
    pin=String(pin||'').replace(/\D/g,'');
    if(!cfg||!cfg.zones||!cfg.zones.length)return o('unconfigured','');
    if(!pinOk(pin))return o('invalid','Enter a 6-digit PIN code.');
    var state=lookup?String(lookup.state||''):null,city=lookup?String(lookup.city||'').trim():'';
    var e=estimate(pin,state,cfg,now);
    if(e)return o('ok',e.label,(city?'To '+city+' · ':'')+e.detail,e);
    if(!lookup)return o('unknown',"We couldn't find PIN code "+pin+'.','Please check the number.');
    if(!state)return o('unconfirmed',"We can't confirm delivery to "+pin+' here.','Checkout shows it before you pay.');
    return o('unserviceable',"Sorry, we don't deliver to "+pin+(city?' ('+city+')':'')+' yet.');
  }
  w.foodifyDelivery={answer:answer,estimate:estimate};
  if(typeof document==='undefined')return;
  function store(v){try{if(v===undefined)return w.localStorage.getItem(KEY);w.localStorage.setItem(KEY,v);}catch(e){}return null;}
  [].forEach.call(document.querySelectorAll('[data-fd-deliver]'),function(box){
    var cfg;try{cfg=JSON.parse(box.getAttribute('data-config')||'');}catch(e){return;}
    var url=box.getAttribute('data-pin-url')||'',form=box.querySelector('form'),input=box.querySelector('input'),out=box.querySelector('.fd-deliver__out'),seq=0;
    function lookup(pin){
      if(typeof w.foodifyPinLookup==='function')return Promise.resolve(w.foodifyPinLookup(pin));
      return fetch(url+pin,{credentials:'same-origin'}).then(function(r){if(!r.ok)throw r;return r.json();}).then(function(j){
        var po=j&&j[0]&&j[0].Status==='Success'&&j[0].PostOffice&&j[0].PostOffice[0];return po?{city:po.District||'',state:po.State||''}:null;});
    }
    function show(a){
      var bad=a.status==='invalid'||a.status==='unknown';
      out.className='fd-deliver__out is-'+(a.status==='ok'?'ok':(bad||a.status==='error'?'error':'no'));
      input.classList.toggle('is-invalid',bad);if(bad)input.setAttribute('aria-invalid','true');else input.removeAttribute('aria-invalid');
      out.textContent='';var b=document.createElement('strong');b.className='fd-deliver__msg';b.textContent=a.message;out.appendChild(b);
      if(a.detail){var s=document.createElement('span');s.className='fd-deliver__detail';s.textContent=a.detail;out.appendChild(s);}
    }
    function check(){
      var pin=(input.value||'').replace(/\D/g,''),n=++seq;
      if(!pinOk(pin)){show(answer(pin,null,cfg,Date.now()));return;}
      out.className='fd-deliver__out';out.textContent='Checking…';input.classList.remove('is-invalid');input.removeAttribute('aria-invalid');
      lookup(pin).then(function(hit){if(n!==seq)return;var a=answer(pin,hit,cfg,Date.now());if(a.status!=='unknown')store(pin);show(a);},
        function(){if(n!==seq)return;show({status:'error',message:"Couldn't check right now.",detail:'Please try again.'});});
    }
    form.addEventListener('submit',function(ev){ev.preventDefault();check();});
    input.addEventListener('input',function(){var v=input.value.replace(/\D/g,'').slice(0,6);if(v!==input.value)input.value=v;if(v.length===6)check();});
    box.hidden=false;
    var saved=store();if(saved&&pinOk(saved)&&!input.value){input.value=saved;check();}
  });
})(typeof window!=='undefined'?window:this);
JS;
}

/**
 * Checkout: pre-fill the PIN the customer already checked on a product, only
 * when the field is empty, and fire the events the existing PIN autofill
 * (checkout-fields.php, jQuery 'input change') listens for, so city and state
 * follow.
 */
function foodify_pin_prefill_script(): string {
	return <<<'JS'
(function(){try{var p=localStorage.getItem('foodify_pin'),f=document.getElementById('billing_postcode');
if(p&&/^[1-9]\d{5}$/.test(p)&&f&&!f.value){f.value=p;['input','change'].forEach(function(t){f.dispatchEvent(new Event(t,{bubbles:true}));});}}catch(e){}})();
JS;
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

/** The configured rules, or null. The one reader of the option. */
function foodify_delivery_config(): ?array {
	return foodify_delivery_normalise_config( get_option( 'foodify_delivery_zones', null ) );
}

/**
 * The widget goes directly UNDER the add-to-cart form, on an in-stock product.
 * UX review 8 Nov 2026, decision 5: quantity + Add to cart first, then the
 * delivery check, then Offers (offers.php, priority 11), then the assurances.
 * Above the form it pushed Add to cart below the first screen on a phone.
 */
add_filter( 'render_block_woocommerce/add-to-cart-form', static function ( string $html ): string {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return $html;
	}
	$product = wc_get_product( get_the_ID() );
	$cfg     = foodify_delivery_config();
	if ( ! $product instanceof WC_Product || ! $product->is_in_stock() || null === $cfg ) {
		return $html;
	}
	$GLOBALS['foodify_delivery_widget_printed'] = true;
	return $html . foodify_delivery_widget_html( $cfg, rest_url( 'foodify/v1/pin/' ) ); // Escaped in the builder.
}, 10 );

add_action( 'wp_footer', static function (): void {
	if ( ! empty( $GLOBALS['foodify_delivery_widget_printed'] ) ) {
		echo '<script>' . foodify_delivery_script() . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- static script, no data.
		return;
	}
	if ( function_exists( 'is_checkout' ) && is_checkout() && ! ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) ) {
		echo '<script>' . foodify_pin_prefill_script() . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- static script, no data.
	}
}, 30 );

/** Name the option that is missing, where the person who can set it will see it. */
add_action( 'admin_notices', static function (): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, [ 'edit-product', 'toplevel_page_foodify-today', 'woocommerce_page_wc-settings' ], true ) ) {
		return;
	}
	if ( null !== foodify_delivery_config() ) {
		return;
	}
	$set = null !== get_option( 'foodify_delivery_zones', null );
	printf(
		'<div class="notice notice-info"><p><strong>%1$s</strong> %2$s</p></div>',
		esc_html__( 'Foodify delivery dates:', 'foodify' ),
		esc_html( $set
			? __( 'the option foodify_delivery_zones is set but invalid, so product pages show no delivery check. Every zone needs a label, states or PIN prefixes, and min/max days; cutoff_hour and no_dispatch_weekdays are required.', 'foodify' )
			: __( 'the option foodify_delivery_zones is not set, so product pages show no delivery check. Set it in bootstrap.sh once the courier zones are confirmed.', 'foodify' ) )
	);
} );
