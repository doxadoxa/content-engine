import {
    NavigationMenu,
    NavigationMenuItem,
    NavigationMenuLink,
    NavigationMenuList,
} from 'avyo';

/**
 * The flat link row. `NavigationMenuTrigger` + `NavigationMenuContent` open a
 * viewport panel on hover, which a single static render cannot show — so the
 * card sticks to the composition that is true without interaction.
 */
export function Links() {
    return (
        <NavigationMenu>
            <NavigationMenuList>
                {['Today', 'Projects', 'Approvals', 'Visibility'].map(
                    (label) => (
                        <NavigationMenuItem key={label}>
                            <NavigationMenuLink href="#">
                                {label}
                            </NavigationMenuLink>
                        </NavigationMenuItem>
                    ),
                )}
            </NavigationMenuList>
        </NavigationMenu>
    );
}

export function WithActive() {
    return (
        <NavigationMenu>
            <NavigationMenuList>
                <NavigationMenuItem>
                    <NavigationMenuLink href="#" data-active>
                        Today
                    </NavigationMenuLink>
                </NavigationMenuItem>
                <NavigationMenuItem>
                    <NavigationMenuLink href="#">Projects</NavigationMenuLink>
                </NavigationMenuItem>
                <NavigationMenuItem>
                    <NavigationMenuLink href="#">Approvals</NavigationMenuLink>
                </NavigationMenuItem>
            </NavigationMenuList>
        </NavigationMenu>
    );
}
