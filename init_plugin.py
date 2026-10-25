#!/usr/bin/env python3
"""Turn this template into a concrete Moodle plugin.

Replaces every placeholder in file contents and file names, then removes itself from the checkout
so a fresh plugin does not carry the template machinery around.

Run once, directly after copying the template:

    python3 init_plugin.py --type enrol --name attendancegate \\
        --title "Attendance gate" --author "Jane Doe" --github janedoe

Placeholders:

    {{PLUGINTYPE}}   auth, enrol, mod, tool, local, block, ...
    {{PLUGINNAME}}   the plugin's own name, lowercase, no underscores
    {{COMPONENT}}    <type>_<name>, or mod_<name> written as the frankenstyle component
    {{PLUGINPATH}}   where the plugin is installed inside a Moodle tree
    {{PLUGINTITLE}}  human-readable name shown to users
    {{AUTHOR}}       copyright holder
    {{GITHUBUSER}}   GitHub account the repository lives under
    {{YEAR}}         copyright year
    {{VERSION}}      initial version number, derived from today's date
"""

import argparse
import datetime
import os
import re
import sys

# Where each plugin type is installed. Add further types as needed - the value is the directory
# relative to the Moodle root, and everything else is derived from it.
PLUGIN_PATHS = {
    'auth': 'auth/{name}',
    'block': 'blocks/{name}',
    'enrol': 'enrol/{name}',
    'local': 'local/{name}',
    'mod': 'mod/{name}',
    'qtype': 'question/type/{name}',
    'report': 'report/{name}',
    'theme': 'theme/{name}',
    'tool': 'admin/tool/{name}',
}

SKIP_DIRS = {'.git', 'node_modules', 'vendor'}
SKIP_FILES = {'package-lock.json'}


def replacements(args):
    """Build the placeholder map.

    :param args: Parsed command line arguments.
    :return: dict of placeholder to value.
    """
    today = datetime.date.today()
    return {
        '{{PLUGINTYPE}}': args.type,
        '{{PLUGINNAME}}': args.name,
        '{{COMPONENT}}': f'{args.type}_{args.name}',
        '{{PLUGINPATH}}': PLUGIN_PATHS[args.type].format(name=args.name),
        '{{PLUGINTITLE}}': args.title,
        '{{AUTHOR}}': args.author,
        '{{GITHUBUSER}}': args.github,
        '{{YEAR}}': str(today.year),
        '{{VERSION}}': today.strftime('%Y%m%d') + '00',
    }


def apply_to_contents(mapping):
    """Replace placeholders inside every text file.

    :param mapping: Placeholder map.
    :return: Number of files changed.
    """
    changed = 0
    for root, dirs, files in os.walk('.'):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
        for name in files:
            if name in SKIP_FILES:
                continue
            path = os.path.join(root, name)
            try:
                with open(path, encoding='utf-8') as handle:
                    src = handle.read()
            except (UnicodeDecodeError, OSError):
                continue
            out = src
            for key, value in mapping.items():
                out = out.replace(key, value)
            if out != src:
                with open(path, 'w', encoding='utf-8') as handle:
                    handle.write(out)
                changed += 1
    return changed


def apply_to_names(mapping):
    """Replace placeholders in file and directory names.

    :param mapping: Placeholder map.
    :return: Number of paths renamed.
    """
    renamed = 0
    # Deepest first, so renaming a directory cannot invalidate paths still to be processed.
    for root, dirs, files in os.walk('.', topdown=False):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
        for name in files + dirs:
            new = name
            for key, value in mapping.items():
                new = new.replace(key, value)
            if new != name:
                os.rename(os.path.join(root, name), os.path.join(root, new))
                renamed += 1
    return renamed


def main():
    parser = argparse.ArgumentParser(description='Initialise this template as a Moodle plugin.')
    parser.add_argument('--type', required=True, choices=sorted(PLUGIN_PATHS),
                        help='Plugin type, for example enrol')
    parser.add_argument('--name', required=True,
                        help='Plugin name: lowercase letters and digits only')
    parser.add_argument('--title', required=True, help='Human-readable name')
    parser.add_argument('--author', required=True, help='Copyright holder')
    parser.add_argument('--github', required=True, help='GitHub account')
    parser.add_argument('--keep', action='store_true',
                        help='Keep this script instead of deleting it')
    args = parser.parse_args()

    if not args.name.isalnum() or not args.name.islower():
        sys.exit('The plugin name must consist of lowercase letters and digits only.')

    mapping = replacements(args)
    print('Ersetzungen:')
    for key, value in mapping.items():
        print(f'  {key:16s} -> {value}')

    changed = apply_to_contents(mapping)
    renamed = apply_to_names(mapping)
    print(f'\nDateien geaendert: {changed}, Pfade umbenannt: {renamed}')

    leftovers = []
    for root, dirs, files in os.walk('.'):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
        for name in files:
            if name in SKIP_FILES:
                continue
            path = os.path.join(root, name)
            try:
                with open(path, encoding='utf-8') as handle:
                    # Only our own placeholders: GitHub Actions expressions and Mustache tags use
                    # the same braces and must not be reported.
                    if re.search(r'\{\{[A-Z_]+\}\}', handle.read()):
                        leftovers.append(path)
            except (UnicodeDecodeError, OSError):
                continue
    if leftovers:
        print('\nWARNUNG: verbliebene Platzhalter in:')
        for path in leftovers:
            print(f'  {path}')
    else:
        print('Keine Platzhalter verblieben.')

    if not args.keep:
        os.remove(__file__)
        print('init_plugin.py entfernt.')

    print('\nNaechste Schritte:')
    print('  1. db/install.xml anlegen, falls das Plugin eigene Tabellen braucht.')
    print('  2. make check ausfuehren.')
    print('  3. In einen Moodle-Baum spiegeln und die Tests laufen lassen.')


if __name__ == '__main__':
    main()
