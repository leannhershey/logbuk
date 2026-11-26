<?php

/* The one occupant form, reused by register-occupant.php and edit-occupant.php. */

$mode    = $mode ?? 'create';
$values  = $values ?? [];
$errors  = $errors ?? [];
$rooms   = $rooms ?? [];
$val     = fn (string $key, string $default = '') => e($values[$key] ?? $default);
$hasErr  = fn (string $key) => isset($errors[$key]) ? 'has-error' : '';

$nextIds = [];
foreach ($rooms as $r) {
    $floor = substr($r['room_id'], 0, 2);
    if (!isset($nextIds[$floor])) {
        try {
            $nextIds[$floor] = next_occupant_id($r['room_id']);
        } catch (Throwable $ex) {
            $nextIds[$floor] = '';
        }
    }
}

$selectedRoom = $values['room_id'] ?? '';
$currentRoom  = null;
foreach ($rooms as $r) {
    if ($r['room_id'] === $selectedRoom) {
        $currentRoom = $r;
        break;
    }
}
?>

<form class="occupant-form" id="occupantForm" method="post" novalidate
      data-mode="<?= e($mode) ?>"
      data-occupant-id="<?= e($occupantId ?? '') ?>"
      data-next-ids='<?= e(json_encode($nextIds)) ?>'
      data-rooms='<?= e(json_encode(array_map(fn ($r) => [
            'room_id' => $r['room_id'], 'capacity' => $r['capacity'],
            'used'    => $r['used'],    'available' => $r['available'], 'is_full' => $r['is_full'],
        ], $rooms))) ?>'>

  <div class="occupant-form__layout">

    <section class="card occupant-form__card">
      <div class="form-grid">

        <div class="field form-grid__full <?= $hasErr('occupant_id') ?>">
          <label for="occupantId">Occupant ID <span class="req">*</span></label>
          <input type="text" id="occupantId" name="occupant_id_display"
                 value="<?= e($displayId ?? '') ?>"
                 placeholder="<?= $mode === 'create' ? 'Select a room first' : '' ?>"
                 readonly aria-readonly="true" class="input--readonly">
          <p class="field__hint" id="idFieldHint">
            <?= $mode === 'create'
                  ? 'Generated from the floor of the assigned room.'
                  : 'Assigned when the occupant was registered. It never changes.' ?>
          </p>
        </div>

        <div class="field <?= $hasErr('first_name') ?>" id="field-first_name">
          <label for="firstName">First Name <span class="req">*</span></label>
          <input type="text" id="firstName" name="first_name" value="<?= $val('first_name') ?>"
                 autocomplete="given-name" maxlength="100" required>
          <p class="field__error" role="alert"><span data-icon="warning"></span><span><?= e($errors['first_name'] ?? '') ?></span></p>
        </div>

        <div class="field <?= $hasErr('last_name') ?>" id="field-last_name">
          <label for="lastName">Last Name <span class="req">*</span></label>
          <input type="text" id="lastName" name="last_name" value="<?= $val('last_name') ?>"
                 autocomplete="family-name" maxlength="50" required>
          <p class="field__error" role="alert"><span data-icon="warning"></span><span><?= e($errors['last_name'] ?? '') ?></span></p>
        </div>

        <div class="field <?= $hasErr('middle_name') ?>" id="field-middle_name">
          <label for="middleName">Middle Name <span class="opt">(Optional)</span></label>
          <input type="text" id="middleName" name="middle_name" value="<?= $val('middle_name') ?>"
                 autocomplete="additional-name" maxlength="50">
          <p class="field__error" role="alert"><span data-icon="warning"></span><span><?= e($errors['middle_name'] ?? '') ?></span></p>
        </div>

        <div class="field <?= $hasErr('suffix') ?>" id="field-suffix">
          <label for="suffix">Suffix <span class="opt">(Optional)</span></label>
          <select id="suffix" name="suffix">
            <?php foreach (['' => 'None', 'Jr.' => 'Jr.', 'Sr.' => 'Sr.', 'II' => 'II', 'III' => 'III', 'IV' => 'IV'] as $v => $label): ?>
              <option value="<?= e($v) ?>" <?= ($values['suffix'] ?? '') === $v ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="field__error" role="alert"><span data-icon="warning"></span><span><?= e($errors['suffix'] ?? '') ?></span></p>
        </div>

        <div class="field <?= $hasErr('program') ?>" id="field-program">
          <label for="program">Program <span class="opt">(Optional)</span></label>
          <input type="text" id="program" name="program" value="<?= $val('program') ?>"
                 placeholder="e.g. BS Nursing" maxlength="100">
          <p class="field__error" role="alert"><span data-icon="warning"></span><span><?= e($errors['program'] ?? '') ?></span></p>
        </div>

        <div class="field <?= $hasErr('contact_number') ?>" id="field-contact_number">
          <label for="contactNumber">Contact Number <span class="req">*</span></label>
          <input type="tel" id="contactNumber" name="contact_number" value="<?= $val('contact_number') ?>"
                 placeholder="09XXXXXXXXX" inputmode="numeric" maxlength="11" required>
          <p class="field__error" role="alert"><span data-icon="warning"></span><span><?= e($errors['contact_number'] ?? '') ?></span></p>
        </div>

        <div class="field form-grid__full <?= $hasErr('room_id') ?>" id="field-room_id">
          <label for="roomId">Assigned Room <span class="req">*</span></label>
          <select id="roomId" name="room_id" required>
            <option value="">Select room</option>
            <?php foreach ($rooms as $room): ?>
              <option value="<?= e($room['room_id']) ?>"
                      data-used="<?= $room['used'] ?>" data-capacity="<?= $room['capacity'] ?>"
                      data-full="<?= $room['is_full'] ? '1' : '0' ?>"
                      <?= $room['is_full'] && $room['room_id'] !== $selectedRoom ? 'disabled' : '' ?>
                      <?= $room['room_id'] === $selectedRoom ? 'selected' : '' ?>>
                <?= e($room['room_id']) ?> (<?= $room['used'] ?>/<?= $room['capacity'] ?>)
                <?= $room['is_full'] ? ' — full' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="field__error" role="alert"><span data-icon="warning"></span><span><?= e($errors['room_id'] ?? '') ?></span></p>
          <p class="field__hint" id="idHint">
            <?= $mode === 'create'
                  ? 'The occupant ID is built from the floor of this room.'
                  : 'The occupant ID stays the same even if you move them to another room.' ?>
          </p>
        </div>
      </div>

      <div class="alert alert--error hidden" id="formAlert" role="alert"></div>

      <div class="occupant-form__actions">
        <button type="submit" class="btn btn--primary" id="saveBtn">
          <?= $mode === 'create' ? 'Save Occupant' : 'Save Changes' ?>
        </button>
        <a class="btn btn--outline" href="occupants.php">Cancel</a>
      </div>
    </section>

    <aside class="card room-panel" id="roomPanel" aria-live="polite">
      <h2 class="room-panel__title">Room Occupancy</h2>
      <p class="room-panel__room" id="roomPanelRoom">
        <?= $currentRoom ? 'Room ' . e($currentRoom['room_id']) : 'No room selected' ?>
      </p>
      <p class="room-panel__count" id="roomPanelCount">
        <?= $currentRoom ? $currentRoom['used'] . '/' . $currentRoom['capacity'] : '—' ?>
      </p>
      <div class="room-panel__people" id="roomPanelPeople">
        <?php
        $cap = $currentRoom['capacity'] ?? 4;
        $used = $currentRoom['used'] ?? 0;
        for ($i = 0; $i < $cap; $i++):
        ?>
          <span class="person <?= $i < $used ? 'person--filled' : '' ?>">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
              <circle cx="12" cy="7.5" r="4"/>
              <path d="M4 21c0-4.4 3.6-7.5 8-7.5s8 3.1 8 7.5z"/>
            </svg>
          </span>
        <?php endfor; ?>
      </div>
      <p class="room-panel__hint" id="roomPanelHint">
        <?= $currentRoom
              ? ($currentRoom['is_full'] ? 'This room is full.' : $currentRoom['available'] . ' slot(s) available.')
              : 'Choose a room to see how many slots are left.' ?>
      </p>
    </aside>
  </div>
</form>
