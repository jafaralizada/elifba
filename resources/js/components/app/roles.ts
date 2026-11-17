import { DoorOpen, House, School } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { Role } from '@/types';

/**
 * Each role's icon, as its area's in the navigation (architecture §1
 * "Accounts and roles"): the school for the teacher, the home for the parent,
 * the open door of a room for the player (§12). The role's name, sentence
 * and agreement are translation keys (`roles.{role}.title`,
 * `roles.{role}.description`, `roles.agreement.{role}`).
 */
export const roleIcons: Record<Role, LucideIcon> = {
    teacher: School,
    parent: House,
    player: DoorOpen,
};
