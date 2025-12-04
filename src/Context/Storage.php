<?php

/**
 * Storage Abstract Class
 *
 * @see \LarAgent\Context\Abstract\Storage
 *
 * A straightforward storage abstraction for DataModel items.
 *
 * Storage API:
 * - get(): returns all items
 * - getIdentity(): returns the identity for this storage
 * - set(array $items): replace all items
 * - getLast(): returns the last item
 * - clear(): sets items as empty array
 * - count(): returns the count of items
 * - save(): persists items to storage (only if dirty)
 * - read(): reads items from storage
 * - isDirty(): checks if items have been modified
 *
 * Dirty tracking:
 * - dirty flag: set to true when items are modified (set, clear)
 * - save() only writes when dirty, then resets the flag
 *
 * Implementation: \LarAgent\Context\Abstract\Storage
 */
